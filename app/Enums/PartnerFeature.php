<?php

namespace App\Enums;

/**
 * What a partner tier unlocks. Stored as a list on partner_tiers.features;
 * the comp-ticket cap is its own column (partner_tiers.comp_tickets), and
 * CompTickets only switches the page on.
 */
enum PartnerFeature: string
{
    case Listing = 'listing';
    case ProfilePage = 'profile_page';
    case LeadsConnect = 'leads_connect';
    case LeadsScan = 'leads_scan';
    case LeadsEnquiry = 'leads_enquiry';
    case Referrals = 'referrals';
    case Analytics = 'analytics';
    case Resources = 'resources';
    case CompTickets = 'comp_tickets';

    public function label(): string
    {
        return match ($this) {
            self::Listing => 'Logo on the event site',
            self::ProfilePage => 'Partner page',
            self::LeadsConnect => 'Leads: Connect button',
            self::LeadsScan => 'Leads: badge scanning',
            self::LeadsEnquiry => 'Leads: enquiry form',
            self::Referrals => 'Referral link and counts',
            self::Analytics => 'Analytics',
            self::Resources => 'Resources and offers',
            self::CompTickets => 'Complimentary tickets',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $f) => ['value' => $f->value, 'label' => $f->label()], self::cases());
    }
}
