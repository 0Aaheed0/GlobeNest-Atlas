<?php

namespace App\Http\Controllers;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Services\AgentToolDisplayPresenter;
use App\Services\AgentToolRegistry;
use App\Services\ChatCompletionProviderException;
use App\Services\OpenRouterAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function history(Request $request, AgentConversation $conversation, AgentToolDisplayPresenter $presenter): JsonResponse
    {
        $ownedConversation = $request->user()->agentConversations()->whereKey($conversation->id)->firstOrFail();

        return response()->json([
            'conversation_id' => $ownedConversation->id,
            'messages' => $ownedConversation->messages()->orderBy('id')->get(['role', 'content', 'tool_result'])
                ->map(fn (AgentMessage $message): array => [
                    'role' => $message->role,
                    'content' => $message->content,
                    'tool_result' => $presenter->presentResults($message->tool_result ?? []),
                ]),
        ]);
    }

    public function chat(Request $request, OpenRouterAgent $agent, AgentToolDisplayPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user();

        if (isset($data['conversation_id'])) {
            $conversation = $user->agentConversations()->whereKey($data['conversation_id'])->firstOrFail();
        } else {
            $conversation = $user->agentConversations()->create([
                'title' => mb_substr(trim($data['message']), 0, 150),
            ]);
        }

        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $data['message'],
        ]);

        try {
            $result = $agent->respond($user, $conversation, $data['message']);
        } catch (ChatCompletionProviderException $exception) {
            $conversation->messages()->create([
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $exception->getMessage(),
                'tool_result' => [['error' => true]],
            ]);
            $conversation->touch();

            return response()->json([
                'message' => $exception->getMessage(),
                'conversation_id' => $conversation->id,
            ], $exception->httpStatus);
        }

        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $result['text'],
            'tool_result' => $result['tool_results'],
        ]);
        $conversation->touch();

        return response()->json([
            'text' => $result['text'],
            'conversation_id' => $conversation->id,
            'tool_results' => $presenter->presentResults($result['tool_results']),
            'pending_actions' => $presenter->presentActions($result['pending_actions']),
        ]);
    }

    public function confirm(Request $request, AgentToolRegistry $tools): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'conversation_id' => ['required', 'integer', 'min:1'],
            'pending_action_id' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'accepted'],
        ])->validate();
        $conversation = $request->user()->agentConversations()->whereKey($data['conversation_id'])->firstOrFail();

        try {
            $saved = $tools->confirm($request->user(), $conversation, (int) $data['pending_action_id']);
        } catch (\InvalidArgumentException|ValidationException $exception) {
            return response()->json([
                'message' => 'The proposed change is no longer valid; nothing was saved.',
                'refresh' => true,
            ], 422);
        }

        // Record the confirmed action as structured task context so subsequent
        // conversational turns can resolve references like "delete the thing you
        // just made" to the exact verified record without relying on model memory.
        $this->recordLastActionContext($conversation, $saved);

        return response()->json([
            'message' => ($saved['deleted'] ?? false)
                ? 'Confirmed and deleted from your account. No payment was made.'
                : 'Confirmed and saved to your account.',
            'saved' => $saved,
        ]);
    }

    public function cancel(Request $request, AgentToolRegistry $tools): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'conversation_id' => ['required', 'integer', 'min:1'],
            'pending_action_id' => ['required', 'integer', 'min:1'],
            'discarded' => ['required', 'accepted'],
        ])->validate();
        $conversation = $request->user()->agentConversations()->whereKey($data['conversation_id'])->firstOrFail();

        try {
            $tools->cancel($request->user(), $conversation, (int) $data['pending_action_id']);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => 'This pending action has already expired or been handled.'], 422);
        }

        // Clear any pending intent context when the user cancels, so the next
        // message starts fresh rather than inheriting a stale workflow.
        $this->clearPendingIntentContext($conversation);

        return response()->json(['message' => 'The proposed change was discarded. Nothing was saved.']);
    }

    public function destroy(Request $request, AgentConversation $conversation): JsonResponse
    {
        $ownedConversation = $request->user()->agentConversations()->whereKey($conversation->id)->firstOrFail();
        $ownedConversation->delete();

        return response()->json([
            'message' => 'Conversation deleted.',
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * After a confirmed action, store structured metadata about the last
     * saved/deleted record. This allows subsequent turns to resolve
     * "delete the thing you just made" to the exact verified ID.
     *
     * @param  array<string, mixed>  $saved
     */
    private function recordLastActionContext(AgentConversation $conversation, array $saved): void
    {
        if (empty($saved['record_type'])) {
            return;
        }

        $existing = is_array($conversation->task_context) ? $conversation->task_context : [];

        // Keep existing pending_intent if there is one; overwrite last_action.
        $existing['last_action'] = array_filter([
            'tool_name' => $saved['record_type'] ?? null,
            'record_type' => $saved['record_type'] ?? null,
            'id' => $saved['id'] ?? null,
            'title' => $saved['title'] ?? null,
            'category' => $saved['category'] ?? null,
            'amount' => $saved['amount'] ?? null,
            'currency' => $saved['currency'] ?? null,
            'deleted' => ($saved['deleted'] ?? false) ? true : null,
        ], fn ($v) => $v !== null);

        // Remove pending_intent once an action has been confirmed.
        unset($existing['pending_intent']);

        $conversation->update(['task_context' => $existing]);
    }

    /**
     * Clear the pending_intent portion of task_context on cancel.
     */
    private function clearPendingIntentContext(AgentConversation $conversation): void
    {
        $existing = is_array($conversation->task_context) ? $conversation->task_context : [];

        if (isset($existing['pending_intent'])) {
            unset($existing['pending_intent']);
            $conversation->update(['task_context' => empty($existing) ? null : $existing]);
        }
    }
}
