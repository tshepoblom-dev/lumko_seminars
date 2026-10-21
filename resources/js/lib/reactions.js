// The emoji reactions attendees can send (App\Enums\ReactionType::current()).
export const REACTIONS = [
    { type: 'thumbs', emoji: '👍', label: 'Thumbs up' },
    { type: 'heart', emoji: '❤️', label: 'Love it' },
    { type: 'clap', emoji: '👏', label: 'Applause' },
    { type: 'party', emoji: '🎉', label: 'Celebrate' },
    { type: 'laugh', emoji: '😂', label: 'Funny' },
    { type: 'think', emoji: '🤔', label: 'Thought-provoking' },
];

export const emojiFor = (type) => REACTIONS.find((r) => r.type === type)?.emoji ?? null;
