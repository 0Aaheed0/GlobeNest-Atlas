<?php

namespace App\Http\Controllers;

use App\Models\AgentConversation;
use App\Services\AgentToolRegistry;
use App\Services\GeminiAgent;
use App\Services\GeminiProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function history(Request $request, AgentConversation $conversation): JsonResponse
    {
        $ownedConversation = $request->user()->agentConversations()->whereKey($conversation->id)->firstOrFail();

        return response()->json([
            'conversation_id' => $ownedConversation->id,
            'messages' => $ownedConversation->messages()->orderBy('id')->get(['role', 'content', 'tool_result']),
        ]);
    }

    public function chat(Request $request, GeminiAgent $agent): JsonResponse
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
        } catch (GeminiProviderException $exception) {
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
        } catch (\RuntimeException $exception) {
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
            ], 503);
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
            'tool_results' => $result['tool_results'],
            'pending_actions' => $result['pending_actions'],
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
            return response()->json(['message' => 'The proposed change is invalid and was not saved.'], 422);
        }

        return response()->json([
            'message' => 'Confirmed and saved to your account.',
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

        return response()->json(['message' => 'The proposed change was discarded. Nothing was saved.']);
    }
}
