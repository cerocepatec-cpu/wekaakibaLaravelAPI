<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Http\Request;
use App\services\Chat\ChatService;
use App\Models\ConversationParticipant;

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

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) =>
                $q->where('user_id', $user->id)
                  ->where('archived', false)
            )
            ->with([
                'participants.user:id,name',
                'messages' => fn ($q) => $q->latest()->limit(1),
            ])
            ->with(['participants' => fn ($q) =>
                $q->where('user_id', $user->id)
            ])
            ->orderByDesc(
                Message::select('created_at')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->latest()
                    ->limit(1)
            )
            ->get()
            ->map(function (Conversation $conversation) use ($user) {

                /** @var ConversationParticipant $participant */
                $participant = $conversation->participants->first();

                $lastReadAt = $participant?->last_read_at;

                $unreadCount = Message::where('conversation_id', $conversation->id)
                    ->where('sender_id', '!=', $user->id)
                    ->when($lastReadAt, fn ($q) =>
                        $q->where('created_at', '>', $lastReadAt)
                    )
                    ->count();

                return [
                    'id' => $conversation->id,
                    'type' => $conversation->type,
                    'status' => $conversation->status,
                    'title' => $conversation->title,
                    'last_message' => $conversation->messages->first(),
                    'unread_count' => $unreadCount,

                    // 👤 Préférences utilisateur
                    'pinned' => $participant->pinned,
                    'muted' => $participant->muted,
                    'archived' => $participant->archived,
                    'notifications_enabled' => $participant->notifications_enabled,

                    'participants' => $conversation->participants->map(fn ($p) => [
                        'id' => $p->user->id,
                        'name' => $p->user->name,
                    ]),
                ];
            })
            // 📌 Conversations épinglées en haut
            ->sortByDesc(fn ($c) => $c['pinned'])
            ->values();

        return response()->json($conversations);
    }

    /* =============================
       MESSAGES D'UNE CONVERSATION
    ============================= */
    public function messages(Conversation $conversation)
    {
        /** @var User $user */
        $user = auth()->user();

        /** @var ConversationParticipant $participant */
        $participant = ConversationParticipant::where([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ])->firstOrFail();

        $messages = Message::where('conversation_id', $conversation->id)
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->paginate(30);

        // 👁️ Marquer comme lu
        $participant->markAsRead();

        return response()->json($messages);
    }

    /* =============================
       MARQUER COMME LU (MANUEL)
    ============================= */
    public function markAsRead(Conversation $conversation)
    {
        /** @var User $user */
        $user = auth()->user();

        $participant = ConversationParticipant::where([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ])->firstOrFail();

        $participant->markAsRead();

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
}
