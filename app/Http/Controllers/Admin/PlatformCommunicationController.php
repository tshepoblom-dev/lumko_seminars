<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesCommunications;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendCommunicationRequest;
use App\Http\Requests\Admin\SendTestCommunicationRequest;
use App\Models\Communication;
use App\Models\CommunicationAttachment;
use App\Services\CommunicationAudience;
use App\Services\CommunicationSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super-admin emails to attendee accounts across every event (for example,
 * inviting past attendees to the next one). Always marketing, so only
 * people with Lumko marketing consent are ever included.
 */
class PlatformCommunicationController extends Controller
{
    use HandlesCommunications;

    public function index(): Response
    {
        $this->authorize('sendPlatformWide', Communication::class);

        return $this->renderIndex(null);
    }

    public function create(Request $request, CommunicationAudience $audience): Response
    {
        $this->authorize('sendPlatformWide', Communication::class);

        return $this->renderCompose($request, null, $audience);
    }

    public function store(SendCommunicationRequest $request, CommunicationAudience $audience, CommunicationSender $sender): RedirectResponse
    {
        $this->authorize('sendPlatformWide', Communication::class);

        return $this->storeCommunication($request, null, $audience, $sender);
    }

    public function test(SendTestCommunicationRequest $request): RedirectResponse
    {
        $this->authorize('sendPlatformWide', Communication::class);

        return $this->sendTest($request, null);
    }

    public function show(Communication $communication): Response
    {
        abort_unless($communication->isPlatformWide(), 404);
        $this->authorize('view', $communication);

        return $this->renderShow(null, $communication);
    }

    public function attachment(Communication $communication, CommunicationAttachment $attachment): StreamedResponse
    {
        abort_unless($communication->isPlatformWide(), 404);
        $this->authorize('view', $communication);

        return $this->downloadAttachment($communication, $attachment);
    }
}
