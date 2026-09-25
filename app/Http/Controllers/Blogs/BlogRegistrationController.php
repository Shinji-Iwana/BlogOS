<?php

namespace App\Http\Controllers\Blogs;

use App\Http\Controllers\Controller;
use App\Services\Blogs\BlogInspectionException;
use App\Services\Blogs\BlogRegistrationService;
use App\Support\QualityProfiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ブログの登録（BLOGOS_WORDPRESS_API.md 29章）。
 */
class BlogRegistrationController extends Controller
{
    public function __construct(
        protected BlogRegistrationService $registrationService
    ) {
    }

    public function create()
    {
        return view('blogs.create', [
            'qualityProfiles' => QualityProfiles::available(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'url'                  => ['required', 'url:http,https', 'max:255'],
            'username'             => ['required', 'string', 'max:255'],
            'application_password' => ['required', 'string', 'max:255'],
            'quality_profile'      => ['nullable', Rule::in(QualityProfiles::available())],
        ]);

        try {
            $result = $this->registrationService->register(
                $validated['url'],
                $validated['username'],
                $validated['application_password'],
                $validated['quality_profile'] ?? null,
                $request->user()?->id
            );
        } catch (BlogInspectionException $e) {
            return back()->withErrors(['url' => $e->getMessage()])->withInput($request->except('application_password'));
        } catch (ConnectionException $e) {
            return back()
                ->withErrors(['url' => 'WordPressに接続できませんでした。時間をおいて再度お試しください。'])
                ->withInput($request->except('application_password'));
        }

        return redirect()
            ->route('database-blog-detail', ['id' => $result['blog']->id])
            ->with('status', 'ブログを登録しました。')
            ->with('registration', [
                'wordpress_user'      => $result['wordpress_user']['name'] ?? '',
                'wordpress_roles'     => $result['wordpress_user']['roles'] ?? [],
                'connector_extension' => $result['connector_extension'],
            ]);
    }
}
