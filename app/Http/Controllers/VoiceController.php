<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Services\Voice\VoiceException;
use App\Services\Voice\VoiceService;
use App\Services\Voice\VoiceSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 音声の操作（ジャービス。D-58）。
 *
 * turn：ブラウザで録音した1回の発言を受け取り、聞き取った文字・返事・返事の声（MP3）・画面を移る URL を JSON で返す。
 * updateSettings：画面「AIの設定」の「音声」（BlogOS 全体の設定）。
 */
class VoiceController extends Controller
{
    /** セッションに覚えておく直前のやり取り */
    protected const HISTORY_KEY = 'voice.history';

    public function __construct(
        protected VoiceService $voice,
        protected VoiceSettings $settings,
        protected BlogRepository $blogs,
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
        ], [
            'mode.in' => 'リアルタイム会話（D・E）は準備中です。今は C を選んでください。',
        ]);

        $this->settings->save([
            'enabled'      => (bool) ($validated['enabled'] ?? false),
            'mode'         => $validated['mode'],
            'voice'        => $validated['voice'],
            'instructions' => (string) ($validated['instructions'] ?? ''),
        ], $request->user()?->id);

        return redirect()->to(route('ai.settings.edit') . '#voice')->with('status', '音声の設定を保存しました。');
    }
}
