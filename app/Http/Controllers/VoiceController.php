<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Services\Voice\VoiceException;
use App\Services\Voice\VoiceRealtimeService;
use App\Services\Voice\VoiceService;
use App\Services\Voice\VoiceSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 音声の操作（ジャービス。D-58）。
 *
 * turn：ブラウザで録音した1回の発言を受け取り、聞き取った文字・返事・返事の声（MP3）・画面を移る URL を JSON で返す。
 * updateSettings：メニューの「設定 → OpenAI → 音声操作」のポップアップ（BlogOS 全体の設定。D-61）。
 */
class VoiceController extends Controller
{
    /** セッションに覚えておく直前のやり取り */
    protected const HISTORY_KEY = 'voice.history';

    public function __construct(
        protected VoiceService $voice,
        protected VoiceSettings $settings,
        protected BlogRepository $blogs,
        protected VoiceRealtimeService $realtime,
    ) {
    }

    public function turn(Request $request)
    {
        $validated = $request->validate([
            'audio'   => ['required', 'file', 'max:' . (int) config('blogos.voice.max_upload_kb')],
            'seconds' => ['required', 'numeric', 'min:0'],
        ]);

        $file = $validated['audio'];
        $history = (array) $request->session()->get(self::HISTORY_KEY, []);

        try {
            $result = $this->voice->turn(
                (string) file_get_contents($file->getRealPath()),
                // 拡張子で音声の形式が判断されるため、ブラウザが付けた名前（例：voice.webm・voice.mp4）を使う
                $file->getClientOriginalName() ?: 'voice.webm',
                (float) $validated['seconds'],
                $this->blogs->findSelected(),
                $request->user()?->id,
                $history,
            );
        } catch (VoiceException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        // 直前のやり取りを覚えておく（「それを開いて」のような続きの発言のため）
        if ($result['transcript'] !== '') {
            $history[] = ['role' => 'user', 'content' => $result['transcript']];
            $history[] = ['role' => 'assistant', 'content' => $result['reply']];
            $request->session()->put(self::HISTORY_KEY, array_slice($history, -2 * (int) config('blogos.voice.history_turns')));
        }

        return response()->json([
            'ok'         => true,
            'transcript' => $result['transcript'],
            'reply'      => $result['reply'],
            'audio'      => $result['audio'] !== null ? base64_encode($result['audio']) : null,
            'mime'       => 'audio/mpeg',
            'navigate'   => $result['navigate'],
            'cost'       => round($result['turn']->estimated_cost, 4),
        ]);
    }

    /**
     * リアルタイム会話を始める（その場限りの鍵。方式 d・e。D-58-06）
     */
    public function realtimeSession()
    {
        try {
            return response()->json(['ok' => true] + $this->realtime->start($this->blogs->findSelected()));
        } catch (VoiceException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * リアルタイム会話で、AI が呼んだ道具を実行する
     */
    public function realtimeTool(Request $request)
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'arguments' => ['nullable', 'string', 'max:5000'],
            'turn'      => ['required', 'integer', 'min:0'],
        ]);

        return response()->json(['ok' => true] + $this->realtime->tool(
            $validated['name'], (string) ($validated['arguments'] ?? '{}'), (int) $validated['turn'], $this->blogs->findSelected(), $request->user()?->id,
        ));
    }

    /**
     * リアルタイム会話の、AI の1回の応答の使用量（費用を残す）
     */
    public function realtimeUsage(Request $request)
    {
        $validated = $request->validate([
            'usage'      => ['required', 'array'],
            'transcript' => ['nullable', 'string', 'max:5000'],
            'reply'      => ['nullable', 'string', 'max:5000'],
            'tools'      => ['nullable', 'array'],
            'tools.*'    => ['string', 'max:100'],
        ]);

        $turn = $this->realtime->record($validated['usage'], (string) ($validated['transcript'] ?? ''), (string) ($validated['reply'] ?? ''), $validated['tools'] ?? [], $this->blogs->findSelected(), $request->user()?->id);

        return response()->json(['ok' => true, 'cost' => round($turn->estimated_cost, 4)]);
    }

    /**
     * 会話の続きを忘れる（新しい会話を始める）
     */
    public function reset(Request $request)
    {
        $request->session()->forget(self::HISTORY_KEY);

        return response()->json(['ok' => true]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'enabled'      => ['nullable', 'boolean'],
            'mode'         => ['required', Rule::in(VoiceSettings::READY_MODES)],
            'voice'        => ['required', Rule::in(config('blogos.voice.voices'))],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->settings->save([
            'enabled'      => (bool) ($validated['enabled'] ?? false),
            'mode'         => $validated['mode'],
            'voice'        => $validated['voice'],
            'instructions' => (string) ($validated['instructions'] ?? ''),
        ], $request->user()?->id);

        // 開いていた画面に戻る（テーマ切替と同じ）
        return back()->with('status', '音声の設定を保存しました。');
    }
}
