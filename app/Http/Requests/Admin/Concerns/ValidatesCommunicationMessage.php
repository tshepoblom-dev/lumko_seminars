<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Communication;
use App\Models\Event;
use Illuminate\Validation\Validator;

/**
 * The message half of a communication (subject, body, attachments), shared
 * by the real send and the test send so a test always goes through the
 * same rules as the email it previews.
 */
trait ValidatesCommunicationMessage
{
    public const MAX_ATTACHMENTS = 5;

    /** Total across all attachments; email encoding adds about a third on top. */
    public const MAX_TOTAL_KB = 10240;

    /**
     * Checked before validation runs, so someone who may not send here
     * gets a 403, not a list of form errors.
     */
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            ? $this->user()->can('sendForEvent', [Communication::class, $event])
            : $this->user()->can('sendPlatformWide', Communication::class);
    }

    protected function messageRules(): array
    {
        return [
            'category' => ['required', 'in:event,marketing'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            // B3 — allow-list both the extension and the detected content,
            // as StoreResourceRequest does.
            'attachments.*' => [
                'file', 'max:'.self::MAX_TOTAL_KB,
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,png,jpg,jpeg',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,text/csv,text/plain,image/png,image/jpeg',
            ],
        ];
    }

    protected function messageAttributes(): array
    {
        return ['attachments.*' => 'attachment'];
    }

    protected function checkAttachmentTotal(Validator $validator): void
    {
        $totalBytes = collect($this->file('attachments', []))->sum(fn ($file) => $file->getSize());

        if ($totalBytes > self::MAX_TOTAL_KB * 1024) {
            $validator->errors()->add('attachments', 'Attachments can be 10 MB at most in total. Link to large files instead.');
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkAttachmentTotal($v));
    }

    public function attributes(): array
    {
        return $this->messageAttributes();
    }
}
