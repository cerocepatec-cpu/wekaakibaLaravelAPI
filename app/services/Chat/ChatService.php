<?php

namespace App\services\Chat;

use App\Models\User;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Support\Facades\Redis;
use App\Models\ConversationParticipant;

class ChatService
{
    public function getOrCreatePrivateConversation(User $a, User $b): Conversation
    {
        $conversation = Conversation::where('type', 'private')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $a->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $b->id))
            ->first();

        if ($conversation) {
            return $conversation;
        }

        $conversation = Conversation::create([
            'type' => 'private',
            'status' => 'active',
        ]);

        $conversation->participants()->createMany([
            [
                'user_id' => $a->id,
                'joined_at' => now(),
            ],
            [
                'user_id' => $b->id,
                'joined_at' => now(),
            ],
        ]);

        return $conversation;
    }


   public function sendMessage(
        int $conversationId,
        ?int $senderId,
        string $content,
        string $type = 'text',
        ?string $clientUuid = null,
        ?array $meta = null
    ): Message {

        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_id'       => $senderId,
            'type'            => $type,
            'content'         => $content,
            'status'          => 'sent',
            'client_uuid'     => $clientUuid,
            'meta'            => $meta,
        ]);

        // 🔹 récupérer les participants
        $participants = ConversationParticipant::where('conversation_id', $conversationId)
            ->pluck('user_id')
            ->toArray();

      Redis::publish('chat-messages', json_encode([
            'event' => 'chat.new-message',
            'data' => [
                'conversationId' => $conversationId,
                'message' => [
                'id' => $message->id,
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'content' => $content,
                'type' => $type,
                'created_at' => $message->created_at,
                ],
                'participants' => $participants,
            ],
        ]));

        return $message;
    }


    public function systemMessage(
        string $scope,
        ?int $targetId,
        string $content
    ): Message {

        $conversationId = match ($scope) {
            'user' => $this->getSystemConversationForUser($targetId),

            'group' => Conversation::where('id', $targetId)
                ->where('type', 'group')
                ->value('id')
                ?? throw new \InvalidArgumentException('Invalid group conversation'),

            'all' => $this->getGlobalSystemConversation(),

            default => throw new \InvalidArgumentException('Invalid scope'),
        };

        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_id'       => null,
            'type'            => 'system',
            'content'         => $content,
            'status'          => 'sent',
        ]);

        Redis::publish('chat-system', json_encode([
            'event' => 'chat.system',
            'data' => [
                'scope'          => $scope,
                'targetId'       => $targetId,
                'conversationId' => $conversationId,
                'messageId'      => $message->id,
            ],
        ]));

        return $message;
    }


    protected function getGlobalSystemConversation(): int
    {
        return Conversation::firstOrCreate(
            ['type' => 'system', 'title' => 'SYSTEM_GLOBAL']
        )->id;
    }

    protected function getSystemConversationForUser(int $userId): int
    {
        $conversation = Conversation::firstOrCreate(
            [
                'type' => 'system',
                'title' => 'SYSTEM_USER_' . $userId,
            ]
        );

        // Associer l’utilisateur si pas encore fait
        ConversationParticipant::firstOrCreate(
            [
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
            ],
            [
                'joined_at' => now(),
                'notifications_enabled' => true,
            ]
        );

        return $conversation->id;
    }

}
