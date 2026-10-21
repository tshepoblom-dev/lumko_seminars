<?php

namespace App\Support;

/**
 * The upload rules for a resource, shared by staff (Admin\Store/Update
 * ResourceRequest) and the speaker and partner portals, so a file one of
 * them rejects is rejected everywhere.
 */
class ResourceRules
{
    /**
     * B3 — allow-list both extension and detected MIME (mimes + mimetypes
     * together): a renamed .html/.svg/.js/.php upload is rejected on
     * content, not just its filename.
     */
    public static function file(): array
    {
        return [
            'nullable', 'file', 'max:20480',
            'mimes:pdf,pptx,ppt,docx,doc,xlsx,xls,csv,png,jpg,jpeg,zip,mp4,txt',
            'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv,image/png,image/jpeg,application/zip,video/mp4,text/plain',
        ];
    }

    /**
     * B3 — https only by default; seminar.allow_http_resource_links is an
     * explicit opt-out for a rare http-only external source.
     */
    public static function externalUrl(): array
    {
        return ['nullable', config('seminar.allow_http_resource_links') ? 'url' : 'url:https', 'max:2048'];
    }
}
