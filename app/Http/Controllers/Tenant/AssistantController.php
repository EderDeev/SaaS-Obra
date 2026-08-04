<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendAssistantMessageRequest;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Tenant;
use App\Services\Assistant\AssistantActionResolver;
use App\Services\Assistant\AssistantRetriever;
use App\Services\Assistant\OpenAiAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AssistantController extends Controller
{
    public function show(Request $request, Tenant $tenant, OpenAiAssistant $assistant): JsonResponse
    {
        $conversation = AiConversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->latest('last_message_at')
            ->latest('id')
            ->first();

        if ($conversation) {
            $conversation->load(['messages' => fn ($query) => $query->latest('id')->limit(40)]);
            $conversation->setRelation('messages', $conversation->messages->sortBy('id')->values());
        }

        return response()->json([
            'available' => $assistant->isAvailable(),
            'conversation' => $conversation ? $this->serializeConversation($conversation) : null,
        ]);
    }

    public function store(
        SendAssistantMessageRequest $request,
        Tenant $tenant,
        AssistantRetriever $retriever,
        AssistantActionResolver $actionResolver,
        OpenAiAssistant $assistant
    ): JsonResponse {
        if (! $assistant->isAvailable()) {
            return response()->json([
                'message' => 'O assistente ainda não está configurado neste ambiente.',
            ], 503);
        }

        $user = $request->user();
        $data = $request->validated();
        $conversation = filled($data['conversation_id'] ?? null)
            ? AiConversation::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->findOrFail((int) $data['conversation_id'])
            : AiConversation::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'title' => Str::limit(trim($data['message']), 80),
                'last_message_at' => now(),
            ]);

        $history = $conversation->messages()
            ->latest('id')
            ->limit(8)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn (AiMessage $message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => trim($data['message']),
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();

        try {
            $sources = $retriever->retrieve(
                $user,
                $tenant,
                $userMessage->content,
                $data['page_path'] ?? null
            );
            $result = $assistant->respond(
                $userMessage->content,
                $sources,
                $history,
                $data['page_title'] ?? null
            );
            $resolvedAction = $actionResolver->resolve(
                $user,
                $tenant,
                $result['action_proposal'] ?? null,
                $sources
            );
            if (($result['action_proposal'] ?? null) && ! $resolvedAction) {
                $result['content'] = 'Não consegui preparar esse rascunho porque o contrato ou a permissão necessária não pôde ser confirmado. Informe o contrato para eu tentar novamente.';
            }
            $relatedSourceIds = collect($result['related_source_ids'] ?? []);
            $navigationLinks = collect($sources)
                ->whereIn('id', $relatedSourceIds)
                ->unique('url')
                ->take(1)
                ->map(fn (array $source): array => [
                    'type' => 'navigation',
                    'id' => $source['id'],
                    'title' => $source['title'],
                    'url' => $source['url'],
                ])
                ->values()
                ->all();
            $messageMetadata = $resolvedAction
                ? [$resolvedAction]
                : $navigationLinks;

            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $result['content'],
                'sources' => $messageMetadata,
                'usage' => $result['usage'],
                'model' => $result['model'],
            ]);
            $conversation->forceFill(['last_message_at' => now()])->save();

            return response()->json([
                'conversation_id' => $conversation->id,
                'user_message' => $this->serializeMessage($userMessage),
                'assistant_message' => $this->serializeMessage($assistantMessage),
            ]);
        } catch (Throwable $exception) {
            Log::error('Falha ao responder no Assistente Deming.', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível consultar o assistente agora. Tente novamente em instantes.',
                'conversation_id' => $conversation->id,
            ], 502);
        }
    }

    public function destroy(Request $request, Tenant $tenant, AiConversation $conversation): JsonResponse
    {
        abort_unless(
            (int) $conversation->tenant_id === (int) $tenant->id
            && (int) $conversation->user_id === (int) $request->user()->id,
            404
        );

        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    private function serializeConversation(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'messages' => $conversation->messages->map(fn (AiMessage $message): array => $this->serializeMessage($message))->values(),
        ];
    }

    private function serializeMessage(AiMessage $message): array
    {
        $links = collect($message->sources ?: [])
            ->filter(fn (array $source): bool => ($source['type'] ?? null) === 'navigation')
            ->map(fn (array $source): array => [
                'id' => $source['id'],
                'title' => $source['title'],
                'url' => $source['url'],
            ])
            ->values()
            ->all();
        $actions = collect($message->sources ?: [])
            ->filter(fn (array $source): bool => in_array($source['type'] ?? null, ['navigate', 'draft'], true))
            ->map(fn (array $source): array => $source)
            ->values()
            ->all();

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'links' => $links,
            'actions' => $actions,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
