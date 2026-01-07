<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Http\Request;
use App\services\Chat\ChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Models\ConversationParticipant;
use App\Http\Resources\ConversationResource;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService
    ) {}

    /* =============================
       ENVOYER MESSAGE
    ============================= */
    public function send(Request $request)
    {
        $data = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'content' => 'required|string|max:5000',
            'client_uuid' => 'nullable|uuid',
            'meta' => 'nullable|array',
        ]);

        /** @var User $user */
        $user = auth()->user();

        // 🔐 Sécurité : participant obligatoire
        $participant = ConversationParticipant::where([
            'conversation_id' => $data['conversation_id'],
            'user_id' => $user->id,
        ])->firstOrFail();

        // 🔒 Conversation verrouillée ?
        abort_if(
            in_array($participant->conversation->status, ['locked', 'closed']),
            403,
            'Conversation verrouillée'
        );

        return $this->chatService->sendMessage(
            $data['conversation_id'],
            $user->id,
            $data['content'],
            'text',
            $data['client_uuid'] ?? null,
            $data['meta'] ?? null
        );
    }

    /* =============================
       LISTE DES CONVERSATIONS
    ============================= */
    public function conversations()
    {
        /** @var User $user */
        $user = auth()->user();

        // Charger les entreprises de l'utilisateur connecté
        $user->load('enterprises:id,name');
        $conversations = Conversation::whereHas('participants', fn ($q) =>
                $q->where('user_id', $user->id)
            )
            ->with([
                'participants.user:id,name,full_name',
                'messages' => fn ($q) => $q->latest()->limit(1),
            ])
            ->get()
            ->sortByDesc(fn ($c) => $c->messages->first()?->created_at)
            ->values();

        return ConversationResource::collection($conversations)
        ->map(fn ($res) => $res->forUser($user));
    }


    /* =============================
       MESSAGES D'UNE CONVERSATION
    ============================= */
   public function messages(Conversation $conversation)
    {
        /** @var User $user */
        $user = auth()->user();

        // 🔐 Sécurité : vérifier que l’utilisateur participe à la conversation
        abort_unless(
            $conversation->participants()
                ->where('user_id', $user->id)
                ->exists(),
            403
        );

        $messages = Message::where('conversation_id', $conversation->id)
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($messages);
    }


    /* =============================
       MARQUER COMME LU (MANUEL)
    ============================= */
    public function markAsRead(Conversation $conversation)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // 🔐 Sécurité : vérifier la participation
        $participant = ConversationParticipant::where([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ])->firstOrFail();

        // 👁️ Marquer comme lu
        $participant->markAsReadAt(now());

        // 🔔 Notifier en temps réel (optionnel)
        Redis::publish('chat-read', json_encode([
            'event' => 'chat.read',
            'data' => [
                'conversationId' => $conversation->id,
                'userId' => $user->id,
            ],
        ]));

        return response()->json(['status' => 'ok']);
    }

    /* =============================
       ARCHIVER / DÉSARCHIVER
    ============================= */
    public function archive(Conversation $conversation)
    {
        $participant = ConversationParticipant::where([
            'conversation_id' => $conversation->id,
            'user_id' => auth()->id(),
        ])->firstOrFail();

        $participant->update([
            'archived' => true,
            'archived_at' => now(),
        ]);

        return response()->json(['archived' => true]);
    }

    public function unarchive(Conversation $conversation)
    {
        $participant = ConversationParticipant::where([
            'conversation_id' => $conversation->id,
            'user_id' => auth()->id(),
        ])->firstOrFail();

        $participant->update([
            'archived' => false,
            'archived_at' => null,
        ]);

        return response()->json(['archived' => false]);
    }

    /* =============================
       PIN / UNPIN
    ============================= */
    public function pin(Conversation $conversation)
    {
        $participant = ConversationParticipant::where([
            'conversation_id' => $conversation->id,
            'user_id' => auth()->id(),
        ])->firstOrFail();

        $participant->update([
            'pinned' => true,
            'pinned_at' => now(),
        ]);

        return response()->json(['pinned' => true]);
    }

    public function unpin(Conversation $conversation)
    {
        $participant = ConversationParticipant::where([
            'conversation_id' => $conversation->id,
            'user_id' => auth()->id(),
        ])->firstOrFail();

        $participant->update([
            'pinned' => false,
            'pinned_at' => null,
        ]);

        return response()->json(['pinned' => false]);
    }

    public function startPrivate(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        /** @var User $me */
        $me = auth()->user();
        $other = User::findOrFail($request->user_id);

        $conversation = DB::transaction(function () use ($me, $other) {
            return $this->chatService
                ->getOrCreatePrivateConversation($me, $other);
        });

        // Charger relations une seule fois
        $conversation->load([
            'participants.user:id,name,full_name',
            'messages' => fn ($q) => $q->latest()->limit(1),
        ]);

        // 🔁 Formater POUR CHAQUE USER
        $forMe = (new ConversationResource($conversation))->forUser($me)->resolve();
        $forOther = (new ConversationResource($conversation))->forUser($other)->resolve();

        // 🔔 PUSH TEMPS RÉEL INTELLIGENT
        Redis::publish('chat.conversation.created', json_encode([
            'users' => [
                $me->id => $forMe,
                $other->id => $forOther,
            ],
        ]));

        // ✅ Retour HTTP (pour l’initiateur)
        return response()->json($forMe);
    }

   public function searchUsers(Request $request)
{
    $q = trim($request->get('q'));
    $me = auth()->user();

    if (strlen($q) < 2) {
        return response()->json([]);
    }

    $users = User::where('id', '!=', $me->id)

        // 🔐 visibilité publique uniquement
        ->whereHas('preference', function ($q) {
            $q->where('visibility', 'public');
        })

        ->where(function ($query) use ($q) {
            $query->where('name', 'like', "%{$q}%")
                ->orWhere('full_name', 'like', "%{$q}%")
                ->orWhere('user_name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('user_phone', 'like', "%{$q}%")
                ->orWhere('uuid', 'like', "%{$q}%");
        })
        ->select([
            'id',
            'uuid',
            'user_name',
            'full_name',
            'name',
            'email',
            'user_phone',
        ])
        ->limit(20)
        ->get();

    $users = $users->map(function ($user) use ($me) {

        $conversation = Conversation::where('type', 'private')
            ->whereHas('participants', fn ($q) =>
                $q->where('user_id', $me->id)
            )
            ->whereHas('participants', fn ($q) =>
                $q->where('user_id', $user->id)
            )
            ->first();

        return [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'user_name' => $user->user_name,
            'full_name' => $user->full_name,
            'name' => $user->name,
            'email' => $user->email,
            'user_phone' => $user->user_phone,

            // 💬 Chat info
            'hasConversation' => (bool) $conversation,
            'conversation_id' => $conversation?->id,
        ];
    });

    return response()->json($users);
}


}
