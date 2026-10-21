<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesCommunications;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendCommunicationRequest;
use App\Http\Requests\Admin\SendTestCommunicationRequest;
use App\Models\Communication;
use App\Models\CommunicationAttachment;
use App\Models\Event;
use App\Services\CommunicationAudience;
use App\Services\CommunicationSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Emails to one event's registrants: everyone, a filtered group, or people
 * picked by hand (one person is an individual email), with attachments.
 */
class CommunicationController extends Controller
{
    use HandlesCommunications;

    public function index(Event $event): Response
    {
        $this->authorize('sendForEvent', [Communication::class, $event]);

        return $this->renderIndex($event);
    }

    public function create(Request $request, Event $event, CommunicationAudience $audience): Response
    {
        $this->authorize('sendForEvent', [Communication::class, $event]);

        return $this->renderCompose($request, $event, $audience);
    }

    public function store(SendCommunicationRequest $request, Event $event, CommunicationAudience $audience, CommunicationSender $sender): RedirectResponse
    {
        $this->authorize('sendForEvent', [Communication::class, $event]);

        return $this->storeCommunication($request, $event, $audience, $sender);
    }

    public function test(SendTestCommunicationRequest $request, Event $event): RedirectResponse
    {
        $this->authorize('sendForEvent', [Communication::class, $event]);

        return $this->sendTest($request, $event);
    }

    public function show(Event $event, Communication $communication): Response
    {
        $this->authorize('view', $communication);

        return $this->renderShow($event, $communication);
    }

    public function attachment(Event $event, Communication $communication, CommunicationAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $communication);

        return $this->downloadAttachment($communication, $attachment);
    }
}
