<?php

namespace App\Enums;

enum ConsentType: string
{
    case Networking = 'networking';
    case LumkoMarketing = 'lumko_marketing';
    case FollowUpRequest = 'follow_up_request';
    // Per event and per speaker: share my contact details with this speaker.
    case SpeakerContact = 'speaker_contact';
    // Per event and per partner: share my contact details with this partner
    // (a lead: Connect, an enquiry, a stand scan, or at registration).
    case PartnerContact = 'partner_contact';
}
