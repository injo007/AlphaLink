<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Arr;
use Random\Randomizer;

/** Recreates DMS generate_sub_domains() with its ordered word pool and selection behavior. */
class DmsBulkInstallSubdomainGenerator
{
    private const KEYWORDS = [
        'rabid', 'picayune', 'plot', 'weigh', 'comment', 'meet', 'paint', 'pollute',
        'acceptable', 'detect', 'one', 'second', 'fowl', 'coherent', 'lizards', 'scatter',
        'consist', 'grouchy', 'alarm', 'abate', 'curvy', 'assorted', 'unequal', 'faded',
        'middle', 'birds', 'vomit', 'sweet', 'yield', 'suggest', 'careful', 'extra-small',
        'stem', 'confess', 'count', 'young', 'achieve', 'help', 'convert', 'boat',
        'hear', 'committee', 'roomy', 'berserk', 'achieve', 'ink', 'nutritious', 'awful',
        'watery', 'ten', 'value', 'wring', 'size', 'popcorn', 'toothpaste', 'tree',
        'face', 'spiritual', 'observant', 'dangerous', 'tend', 'attempt', 'have', 'nourish',
        'rightful', 'squeeze', 'railway', 'obstruct', 'bomb', 'lame', 'womanly', 'hideous',
        'hope', 'spurious', 'hunt', 'toad', 'compel', 'view', 'mow', 'cleave',
        'special', 'sun', 'zebra', 'note', 'attack', 'books', 'mountainous', 'addicted',
        'anger', 'shirk', 'open', 'roomy', 'oblige', 'cling', 'weather', 'brake',
        'thoughtless', 'handsomely', 'sail', 'sabotage', 'grain', 'faint', 'constitute', 'slide',
        'cloud', 'excellent', 'implant', 'gate', 'evanescent', 'preside', 'eye', 'spray',
        'nice', 'sleet', 'humorous', 'farm', 'mere', 'untidy', 'many', 'languid',
        'baseball', 'hot', 'create', 'abortive', 'humor', 'submit', 'sow', 'share',
        'bang', 'disappear', 'rule', 'malicious', 'dinosaurs', 'curb', 'improve', 'damage',
        'proceed', 'spiky', 'woebegone', 'class', 'swift', 'numberless', 'race', 'offer',
        'proud', 'towering', 'encourage', 'sadden', 'whisper', 'exchange', 'towering', 'apathetic',
        'furry', 'ajar', 'apologize', 'nostalgic', 'stimulate', 'point', 'spoon', 'breakable',
        'detail', 'board', 'short', 'partake', 'know', 'sew', 'vanish', 'cumbersome',
        'girl', 'voice', 'stick', 'seize', 'flagrant', 'government', 'lively', 'laugh',
        'crush', 'cook', 'moan', 'gaze', 'frogs', 'addition', 'shake', 'crime',
        'indulge', 'stare', 'panoramic', 'lamentable', 'vary', 'voiceless', 'leak', 'channel',
        'rest', 'legal', 'boundary', 'guiltless', 'continue', 'giant', 'children', 'abhorrent',
        'burly', 'wealth', 'sisters', 'pump', 'paper', 'come', 'plough', 'gratis',
        'metal', 'annoyed', 'motionless', 'psychedelic', 'staking', 'band', 'chew', 'useless',
        'hope', 'awake', 'create', 'pear', 'signal', 'boundless', 'bust', 'aware',
        'rest', 'evanescent', 'finicky', 'pen', 'swing', 'wiry', 'faucet', 'terrify',
        'arm', 'bear', 'care', 'allow', 'powder', 'sun', 'damage', 'nurse',
        'necessary', 'eat', 'rabid', 'brake', 'wax', 'brake', 'receipt', 'rend',
        'cover', 'yard', 'lethal', 'memory', 'church', 'craven', 'base', 'crush',
        'progress', 'drab', 'sneak', 'slope', 'hinder', 'inspire', 'festive', 'guide',
        'immolate', 'decorous', 'church', 'ball', 'scarce', 'splendid', 'contrast', 'abiding',
        'eyes', 'vagabond', 'creepy', 'sadden', 'download', 'annoyed', 'craven', 'pen',
        'guarded', 'tread', 'belief', 'stop', 'accompany', 'survey', 'farm', 'ply',
        'ignore', 'sleep', 'route', 'chilly', 'petite', 'river', 'breathe', 'busy',
        'chilly', 'wasteful', 'expensive', 'sing', 'luxuriant', 'necessary', 'face', 'feast',
        'parsimonious', 'crave', 'rest', 'match', 'calm', 'peaceful', 'guide', 'dog',
        'terrify', 'inquire', 'learning', 'low', 'punish', 'gaudy', 'chickens', 'fairies',
        'knot', 'dreary', 'bleed', 'suggest', 'ship', 'slope', 'lewd', 'hearing',
        'encircle', 'diligent', 'sulky', 'soft', 'left', 'guide', 'oatmeal', 'cook',
        'park', 'talented', 'expand', 'import', 'teeth', 'terminate', 'drink', 'lift',
        'believe', 'mask', 'flash', 'journey', 'soap', 'oranges', 'turkey', 'taste',
        'splendid', 'ill-fated', 'accompany', 'enormous', 'caress', 'frighten', 'nondescript', 'friends',
        'apples', 'rich', 'delete', 'tee', 'recur', 'oil', 'cast', 'hollow',
        'signify', 'key', 'pest', 'knowing', 'opposite', 'whisper', 'cash', 'redo',
        'house', 'worship', 'mushy', 'dynamic', 'occupy', 'satiate', 'nifty', 'passenger',
        'cakes', 'sing', 'milk', 'rod', 'blue-eyed', 'adamant', 'faded', 'painful',
        'derive', 'sick', 'pathetic', 'value', 'cover', 'glitter', 'hate', 'tempt',
        'tame', 'amused', 'rhythm', 'material', 'government', 'four', 'shiver', 'handy',
        'picayune', 'veil', 'honorable', 'glorious', 'shirk', 'ferry', 'challenge', 'silky',
        'engrave', 'dime', 'sate', 'print', 'elite', 'fowl', 'cloudy', 'frame',
        'sink', 'familiar', 'abstracted', 'combative', 'bright', 'stink', 'nice', 'difficult',
        'baseball', 'saturate', 'unhealthy', 'divide', 'rod', 'crowd', 'arrange', 'certain',
        'endurable', 'remarkable', 'parsimonious', 'crazy', 'mute', 'lip', 'synonymous', 'sail',
        'sassy', 'legs', 'dime', 'shame', 'squalid', 'gaping', 'sneaky', 'clock',
        'bubble', 'pen', 'next', 'grubby', 'want', 'married', 'fan', 'zoo',
        'protective', 'bread', 'dip', 'pause', 'seat', 'indicate', 'fear', 'wax',
        'nosy', 'sidewalk', 'differ', 'depressed', 'endure', 'crowd', 'observe', 'vary',
        'control', 'rotten', 'elfin', 'sweet', 'domineering', 'ambiguous', 'bit', 'blushing',
        'berry', 'difficult', 'supreme', 'misuse', 'powder', 'camera', 'amused', 'learn',
        'thirsty', 'steel', 'burn', 'oppress', 'prefer', 'hat', 'treatment', 'creature',
        'quit', 'ill-informed', 'private', 'icicle', 'smother', 'things', 'discreet', 'nostalgic',
        'exciting', 'dine', 'square', 'shoes', 'preserve', 'doubtful', 'eat', 'dirty',
        'disagreeable', 'boring', 'lumpy', 'lick', 'hobbies', 'rhythm', 'fair', 'discuss',
        'earthquake', 'mark', 'collect', 'display', 'collect', 'vanish', 'uneven', 'cheerful',
        'turkey', 'prefer', 'toothbrush', 'kiss', 'milky', 'spring', 'science', 'nest',
        'admire', 'holiday', 'wish', 'empower', 'roof', 'six', 'correspon', 'cows',
        'scientific', 'invent', 'liquid', 'dramatic', 'abaft', 'pay', 'rub', 'pushy',
        'unit', 'achieve', 'wiggly', 'government', 'capricious', 'bread', 'digestion', 'hilarious',
        'nice', 'doctor', 'great', 'stormy', 'illustrate', 'metal', 'private', 'spotless',
        'pale', 'flagrant', 'flag', 'roof', 'glove', 'homeless', 'language', 'grip',
        'cause', 'cook', 'cows', 'implore', 'cloudy', 'bow', 'striped', 'broadcast',
        'shoes', 'addicted', 'bewildered', 'fear', 'snap', 'thrill', 'sting', 'clever',
        'scissors', 'beat', 'cakes', 'husky', 'give', 'dive', 'capture', 'innovate',
        'wistful', 'hope', 'shop', 'envy', 'seek', 'ask', 'vacuous', 'longing',
        'table', 'encroach', 'colour', 'yield', 'power', 'sabotage', 'mint', 'skillful',
        'alluring', 'group', 'cheer', 'science', 'see', 'advice', 'vigorous', 'grain',
        'strike', 'bind', 'even', 'punish', 'refuse', 'succeed', 'servant', 'naughty',
        'introduce', 'snotty', 'minor', 'eggs', 'moon', 'impel', 'texture', 'able',
        'cumbersome', 'outstanding', 'hope', 'soar', 'wake', 'painstaking', 'smother', 'begin',
        'futuristic', 'shut', 'run', 'door', 'tight', 'pastoral', 'fancy', 'come',
        'poised', 'enlarge', 'pail', 'meal', 'religion', 'read', 'long', 'burst',
        'base', 'moan', 'selfish', 'secretary', 'peaceful', 'punish', 'excellent', 'wish',
        'wanting', 'oil', 'puzzling', 'kiss', 'lie', 'wealth', 'late', 'tense',
        'yawn', 'saturate', 'momentous', 'typical', 'coordinated', 'toad', 'color', 'bitter',
        'ski', 'part', 'strange', 'feel', 'robust', 'action', 'stereotyped', 'dear',
        'smoggy', 'mountain', 'lethal', 'evasive', 'glance', 'guarded', 'needless', 'penitent',
        'abounding', 'work', 'impolite', 'corn', 'imagine', 'flop', 'adamant', 'bite',
        'hide', 'brown', 'railway', 'hurt', 'driving', 'gray', 'bloody', 'acquire',
        'thing', 'bun', 'nest', 'curve', 'tax', 'illumine', 'lose', 'hall',
        'yell', 'beginner', 'shed', 'bell', 'tender', 'jail', 'march', 'watery',
        'future', 'indent', 'mislead', 'second', 'slim', 'chivalrous', 'weak', 'nip',
        'free', 'fine', 'delicious', 'create', 'desk', 'magic', 'watch', 'powder',
        'degree', 'changeable', 'nonstop', 'identify', 'fluttering', 'design', 'disgust', 'gaze',
        'buzz', 'sleet', 'incompetent', 'omit', 'rid', 'co-operate', 'warn', 'week',
        'implicate', 'penitent', 'curve', 'geese', 'beetle', 'endorse', 'send', 'fat',
        'vein', 'holiday', 'brief', 'import', 'acoustic', 'sew', 'cars', 'use',
        'assert', 'sink', 'tired', 'prove', 'gamy', 'messy', 'account', 'use',
        'fax', 'wring', 'disuse', 'support', 'separate', 'convey', 'shrink', 'silk',
        'woman', 'cycle', 'alarm', 'shape', 'side', 'tap', 'unruly', 'obey',
        'honorable', 'permit', 'axiomatic', 'bet', 'momentous', 'disease', 'scare', 'ten',
        'writer', 'ink', 'achiever', 'number', 'chat', 'nostalgic', 'unsuitable', 'glance',
        'glistening', 'sophisticated', 'jam', 'divide', 'impede', 'mark', 'acrid', 'magic',
        'giraffe', 'annoying', 'bite-sized', 'stray', 'brass', 'partake', 'thin', 'quill',
        'vein', 'hands', 'talk', 'fragile', 'loving', 'payment', 'hammer', 'macho',
        'connote', 'insurance', 'current', 'surpass', 'bend', 'iron', 'like', 'direction',
        'show', 'mask', 'curvy', 'sashay', 'quarter', 'body', 'fax', 'magenta',
        'exciting', 'yellow', 'steady', 'alley', 'sky', 'repair', 'bikes', 'list',
        'baseball', 'fowl', 'quince', 'meet', 'crooked', 'optimal', 'foretell', 'measure',
        'break', 'sick', 'dye', 'quack', 'comparison', 'important', 'salty', 'spooky',
        'regret', 'need', 'lean', 'weary', 'synonymous', 'integrate', 'alive', 'worthless',
        'worry', 'recess', 'mean', 'display', 'fit', 'melt', 'water', 'onerous',
        'driving', 'car', 'comb', 'immerse', 'bumpy', 'grouchy', 'chilly', 'bite',
        'elegant', 'scald', 'needle', 'theory', 'consult', 'expect', 'acceptable', 'obedient',
        'disastrous', 'well-off', 'swell', 'note', 'drill', 'break', 'woebegone', 'hair',
        'smash', 'play', 'poison', 'stomach', 'neck', 'apparatus', 'comb', 'redundant',
        'fluttering', 'canvass', 'faded', 'weak', 'afternoon', 'wistful', 'bean', 'hospitable',
        'rustic', 'selfish', 'handy', 'addition', 'cows', 'texture', 'abide', 'square',
        'level', 'scale', 'macabre', 'truculent', 'fly', 'contest', 'nine', 'condition',
        'reply', 'relax', 'wax', 'design', 'encircle', 'determined', 'tremendous', 'fantastic',
        'dog', 'ocean', 'person', 'violent', 'expansion', 'tramp', 'jumbled', 'steady',
        'scrub', 'group', 'questionable', 'thrive', 'female', 'redundant', 'harmony', 'long-term',
        'vast', 'foamy', 'guitar', 'lyrical', 'amused', 'endurable', 'inflate', 'broad',
        'annoying', 'riddle', 'distribution', 'wax', 'tax', 'work', 'royal', 'hurt',
        'withdraw', 'victorious', 'cry', 'produce', 'numerous', 'converge', 'wretched', 'full',
        'hot', 'successful', 'guitar', 'inquire', 'longing', 'type',
    ];

    public function __construct(private readonly ?Randomizer $randomizer = null) {}

    /** @return list<string> */
    public function generate(int $count): array
    {
        if ($this->randomizer === null || $count > count(self::KEYWORDS)) {
            return Arr::random(self::KEYWORDS, $count);
        }

        if ($count <= 0) {
            return [];
        }

        $selectedKeys = $this->randomizer->pickArrayKeys(self::KEYWORDS, $count);

        return array_map(static fn (int|string $key): string => self::KEYWORDS[$key], $selectedKeys);
    }
}
