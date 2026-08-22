<?php

declare(strict_types=1);

/**
 * Learning catalogue and demo learning history.
 *
 * The categories, courses, lessons and quizzes are reference data and seed
 * unconditionally. Enrolments, watch history, quiz attempts and certificates
 * are demo data and only appear when SEED_DEMO_DATA is on.
 *
 * Every identifier is derived from a stable key rather than generated, so the
 * whole file can run on every boot without ever producing a second copy of a
 * row. Anything referring to a person uses the fixed employee identifiers the
 * platform shares.
 */

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\DemoCohort;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

$pdo = Connection::pdo();
$now = Clock::now();

// Training is assigned to the people still employed.
$employees = DemoCohort::activeEmployeeIds();

/**
 * Builds a stable identifier from a descriptive key.
 *
 * Seeds must be re-runnable, which means a row has to arrive at the same
 * primary key every time. Deriving it from the key is what lets the inserts
 * below be plain "do nothing on conflict" statements instead of a lookup per
 * row, and keeps a fifty line wall of literal identifiers out of the file.
 */
$identifier = static function (string $key): string {
    $hash = md5('dayflow.learning.' . $key);
    $variant = ['8', '9', 'a', 'b'][hexdec($hash[16]) % 4];

    return sprintf(
        '%s-%s-4%s-%s%s-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 13, 3),
        $variant,
        substr($hash, 17, 3),
        substr($hash, 20, 12)
    );
};

$insert = static function (string $table, array $row, string $conflictTarget) use ($pdo): void {
    $columns = array_keys($row);

    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
        Connection::quoteIdentifier($table),
        implode(', ', array_map([Connection::class, 'quoteIdentifier'], $columns)),
        implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)),
        $conflictTarget
    );

    foreach ($row as $column => $value) {
        if (is_bool($value)) {
            $row[$column] = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $row[$column] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    $pdo->prepare($sql)->execute($row);
};

$moment = static fn (int $daysAgo): string => $now
    ->modify(sprintf('-%d days', max(0, $daysAgo)))
    ->format(\DateTimeInterface::ATOM);

$calendarDay = static fn (int $offsetDays): string => $now
    ->modify(sprintf('%+d days', $offsetDays))
    ->format('Y-m-d');

// ---------------------------------------------------------------------------
// Reference data: categories
// ---------------------------------------------------------------------------

$categories = [
    ['slug' => 'compliance', 'name' => 'Compliance', 'icon' => 'shield-check', 'colour' => '#B4443C',
        'description' => 'Training the company is obliged to deliver and record.'],
    ['slug' => 'technology', 'name' => 'Technology', 'icon' => 'terminal', 'colour' => '#2D6CDF',
        'description' => 'Engineering craft, tooling and the systems we build on.'],
    ['slug' => 'leadership', 'name' => 'Leadership', 'icon' => 'compass', 'colour' => '#6A4FB6',
        'description' => 'Leading people, teams and decisions with or without a title.'],
    ['slug' => 'product', 'name' => 'Product', 'icon' => 'lightbulb', 'colour' => '#0E8C6A',
        'description' => 'Discovery, positioning and getting the right thing built.'],
    ['slug' => 'wellbeing', 'name' => 'Wellbeing', 'icon' => 'heart-pulse', 'colour' => '#D08428',
        'description' => 'Energy, focus and staying well over a long career.'],
    ['slug' => 'onboarding', 'name' => 'Onboarding', 'icon' => 'door-open', 'colour' => '#3C7C8C',
        'description' => 'Everything a new joiner needs in their first fortnight.'],
];

foreach ($categories as $order => $category) {
    $insert('course_categories', [
        'id' => $identifier('category:' . $category['slug']),
        'name' => $category['name'],
        'slug' => $category['slug'],
        'description' => $category['description'],
        'icon' => $category['icon'],
        'colour' => $category['colour'],
        'display_order' => ($order + 1) * 10,
        'is_active' => true,
        'created_at' => $moment(400),
        'updated_at' => $moment(400),
    ], 'id');
}

// ---------------------------------------------------------------------------
// Reference data: the course catalogue
//
// Lesson content is public YouTube material, stored as the pasted address plus
// the extracted video id. Nothing is hosted by the platform.
// ---------------------------------------------------------------------------

$catalogue = [
    [
        'slug' => 'information-security-essentials',
        'category' => 'compliance',
        'title' => 'Information Security Essentials',
        'summary' => 'The everyday security habits every employee is accountable for.',
        'description' => 'How data actually travels, why credentials are the softest target in any organisation, and the handful of habits that stop most incidents before they start. Required annually for everyone with a company account.',
        'level' => 'beginner',
        'mandatory' => true,
        'passing_score' => 80,
        'certificate' => true,
        'published_days_ago' => 300,
        'lessons' => [
            ['How your data actually travels', 'A plain explanation of what happens between your keyboard and a server, and where somebody could be listening.', 'inWWhr5tnEA', 340, true],
            ['Choosing a password that holds up', 'Why length beats complexity, and what a cracking attempt really looks like.', '3NjQ9b3pgIg', 512, false],
            ['How credentials get stolen at scale', 'What happens to a password database once it leaks, and why reuse is the real danger.', '8ZtInClXe1Q', 552, false],
            ['Public key cryptography, without the maths', 'The idea behind the padlock in your address bar, explained from first principles.', 'AQDCe585Lnc', 388, false],
        ],
    ],
    [
        'slug' => 'respectful-workplace',
        'category' => 'compliance',
        'title' => 'Respectful Workplace and Anti-Harassment',
        'summary' => 'Recognising bias and harassment, and knowing exactly what to do about it.',
        'description' => 'A practical grounding in the behaviours that make a workplace safe to speak up in: how bias forms, what harassment looks like before it becomes obvious, and the routes open to anyone who needs to raise something.',
        'level' => 'beginner',
        'mandatory' => true,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 320,
        'lessons' => [
            ['The danger of a single story', 'How incomplete narratives about people shape the way we treat them.', 'D9Ihs241zeg', 1123, true],
            ['Walking towards our own biases', 'Naming the assumptions we make before they turn into decisions.', 'uYyvbgINZkQ', 1080, false],
            ['Why we still have too few women leaders', 'The structural and everyday reasons progression stalls, and what changes it.', '18uDutylDa4', 909, false],
            ['Speaking up for yourself', 'How to raise a difficult thing without losing the room.', 'qEcVSKuS3Ok', 366, false],
        ],
    ],
    [
        'slug' => 'data-privacy-and-protection',
        'category' => 'compliance',
        'title' => 'Data Privacy and Protection',
        'summary' => 'What personal data we hold, why it matters, and how it is protected.',
        'description' => 'Personal data is the most sensitive thing this company holds. This course covers why privacy is worth defending even when you have nothing to hide, and the mechanisms that keep records confidential in transit and at rest.',
        'level' => 'intermediate',
        'mandatory' => true,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 260,
        'lessons' => [
            ['Why privacy matters', 'The argument against the idea that only wrongdoers need privacy.', 'pcSlowAhvUk', 1188, true],
            ['Agreeing a secret in the open', 'How two parties who have never met establish a key nobody else can read.', 'NmM9HA2MQGI', 512, false],
            ['Hashing, integrity and tamper evidence', 'How we prove a record has not been altered since it was written.', 'b4b8ktEV4Bg', 480, false],
        ],
    ],
    [
        'slug' => 'welcome-to-dayflow',
        'category' => 'onboarding',
        'title' => 'Welcome to Dayflow',
        'summary' => 'Why the company exists, how it decides, and what it expects of you.',
        'description' => 'The first course every joiner takes. It sets out the thinking behind how the company is run: purpose before process, motivation that survives contact with reality, and the persistence that gets hard work finished.',
        'level' => 'beginner',
        'mandatory' => true,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 365,
        'lessons' => [
            ['Start with why', 'Purpose as the thing that makes everything else legible.', 'qp0HIF3SfI4', 1083, true],
            ['The puzzle of motivation', 'What the evidence says actually moves people at work.', 'rrkrvAUbU9Y', 1128, false],
            ['Grit: passion and perseverance', 'Why sustained effort predicts outcomes better than raw talent.', 'H14bBuluwB8', 366, false],
            ['The power of vulnerability', 'How candour makes teams safer and faster.', 'iCvmsMzlF7o', 1220, false],
        ],
    ],
    [
        'slug' => 'working-at-dayflow',
        'category' => 'onboarding',
        'title' => 'Working at Dayflow: Habits and Rituals',
        'summary' => 'How we plan, speak and listen day to day.',
        'description' => 'The working habits that make a distributed company function: honest planning against your own procrastination, speaking so people can act on what you said, and listening well enough to hear what was meant.',
        'level' => 'beginner',
        'mandatory' => true,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 355,
        'lessons' => [
            ['Inside the mind of a master procrastinator', 'Why deadlines work, and what happens when there is not one.', 'arj7oStGLkU', 843, true],
            ['Speaking so that people want to listen', 'The habits that make a spoken point land.', 'eIho2S0ZahI', 597, false],
            ['Believing that you can improve', 'The difference a growth mindset makes to how feedback is received.', '_X0mgOOSpLU', 636, false],
        ],
    ],
    [
        'slug' => 'git-and-version-control',
        'category' => 'technology',
        'title' => 'Git and Version Control Fundamentals',
        'summary' => 'From your first commit to confidently rewriting history.',
        'description' => 'A complete grounding in Git for anyone who touches a repository. Covers the mental model behind commits and branches, the merge and rebase decision, a branching strategy that survives a growing team, and how to recover from the mistakes everybody eventually makes.',
        'level' => 'beginner',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => true,
        'published_days_ago' => 240,
        'lessons' => [
            ['Git and GitHub from scratch', 'The complete beginner path: repositories, commits, remotes and pull requests.', 'RGOj5yH7evk', 3806, true],
            ['Working with branches every day', 'Creating, switching and finishing branches without losing work.', '8JJ101D3knE', 4756, false],
            ['Merge or rebase?', 'What each one does to history and when to reach for which.', '0chZFIZLR_0', 585, false],
            ['A branching strategy that scales', 'Keeping a shared trunk releasable as the team grows.', 'Uszj_k0DGsg', 900, false],
            ['Undoing things safely', 'Reset, revert and reflog, and which one to use when you have broken something.', 'FdZecVxzJbk', 1080, false],
        ],
    ],
    [
        'slug' => 'sql-for-everyday-analysis',
        'category' => 'technology',
        'title' => 'SQL for Everyday Analysis',
        'summary' => 'Answer your own questions about the data instead of queueing for them.',
        'description' => 'Enough SQL to stop filing tickets for numbers you could pull yourself. Selecting and filtering, joining across tables, aggregating honestly, window functions for running totals and rankings, and reading a query plan when something is slow.',
        'level' => 'intermediate',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 210,
        'lessons' => [
            ['SQL in an hour', 'Tables, SELECT, WHERE and ORDER BY, end to end.', '9Pzj7Aj25lw', 3600, true],
            ['Joins, finally explained', 'Inner, left and the ones people get wrong.', '9yeOJ0ZMUYw', 720, false],
            ['Grouping and aggregation', 'COUNT, SUM and GROUP BY without accidentally lying.', 'nNrgRVIfKDk', 900, false],
            ['Window functions', 'Running totals, rankings and comparisons across rows.', 'Ww71knvhQ-s', 1260, false],
            ['Reading a query plan', 'Finding out why a query is slow before adding an index at random.', 'Y8oTC1jRk5o', 1500, false],
        ],
    ],
    [
        'slug' => 'containers-and-docker',
        'category' => 'technology',
        'title' => 'Containers and Docker in Practice',
        'summary' => 'Package an application so it runs the same everywhere.',
        'description' => 'What a container actually is, how images are layered and cached, wiring several services together with Compose, and the networking model that decides what can reach what.',
        'level' => 'intermediate',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 180,
        'lessons' => [
            ['Docker from the ground up', 'Images, containers, volumes and the daemon behind them.', 'fqMOX6JJhGo', 7620, true],
            ['Containers in one hundred seconds', 'The shortest useful explanation of the idea.', 'Gjnup-PuquQ', 155, false],
            ['Composing several services', 'Describing a whole stack in one file and bringing it up together.', 'HG6yIjZapSA', 900, false],
            ['Container networking', 'How containers find each other and what stays private.', '3c-iBn73dDE', 1800, false],
        ],
    ],
    [
        'slug' => 'modern-javascript-foundations',
        'category' => 'technology',
        'title' => 'Modern JavaScript Foundations',
        'summary' => 'The language as it is written today, not as it was in 2012.',
        'description' => 'A working foundation in modern JavaScript: the core language, the asynchronous model that trips everyone up, promises and async/await, talking to an API, and organising code into modules.',
        'level' => 'beginner',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 195,
        'lessons' => [
            ['JavaScript for beginners', 'Values, functions, objects and control flow.', 'W6NZfCO5SIk', 3720, true],
            ['A crash course in the language', 'A faster second pass over the same ground, with more practice.', 'hdI2bqOjy3c', 4000, false],
            ['Promises in ten minutes', 'What a promise is and why callbacks were replaced.', 'DHvZLI7Db8E', 630, false],
            ['Async and await', 'Writing asynchronous code that reads like ordinary code.', 'li7FzDHYZpc', 1020, false],
            ['Fetching data from an API', 'Making requests, handling failures and parsing responses.', 'ZYb_ZU8LNxs', 420, false],
            ['Organising code into modules', 'Imports, exports and keeping a growing codebase navigable.', 'NCwa_xi0Uuc', 900, false],
        ],
    ],
    [
        'slug' => 'leading-without-authority',
        'category' => 'leadership',
        'title' => 'Leading Without Authority',
        'summary' => 'Influence when nobody reports to you.',
        'description' => 'Most of the leadership anybody does happens sideways. This course covers safety as the foundation of a team that performs, presence, the quiet contributors you are probably overlooking, the role of candour, and how trust is rebuilt once it has been lost.',
        'level' => 'intermediate',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 220,
        'lessons' => [
            ['Why good leaders make you feel safe', 'Safety as the precondition for everything else a team does.', 'lmyZMtPVodo', 728, true],
            ['Presence and how you carry a room', 'What posture and preparation do to your own confidence.', 'Ks-_Mh1QhMc', 1263, false],
            ['The power of introverts', 'The contribution you lose by rewarding only the loudest voice.', 'Mde2q7GFCrw', 1148, false],
            ['Candour without cruelty', 'Saying the difficult thing in a way that can be heard.', 'X4Qm9cGRub0', 1256, false],
            ['Building and rebuilding trust', 'The three things trust rests on, and which one usually broke.', 'hER0Qp6QJNU', 900, false],
        ],
    ],
    [
        'slug' => 'coaching-conversations',
        'category' => 'leadership',
        'title' => 'Coaching Conversations for Managers',
        'summary' => 'Listening, feedback and the one-to-one that is worth having.',
        'description' => 'For anyone with direct reports. How to listen for what was not said, structure feedback so it changes something, hold a conversation instead of taking turns to broadcast, and understand what actually drives the person in front of you.',
        'level' => 'advanced',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 150,
        'lessons' => [
            ['Five ways to listen better', 'Listening as a skill that decays without deliberate practice.', 'cSohjlYQI2A', 465, true],
            ['Giving feedback that lands', 'A structure that separates the observation from the judgement.', 'wtl5UrrgU8c', 300, false],
            ['Ten ways to have a better conversation', 'Habits that turn an exchange into an actual conversation.', 'R1vskiVDwl4', 705, false],
            ['Why we do what we do', 'The needs underneath behaviour you find hard to explain.', 'Cpc-t-Uwv1I', 1332, false],
        ],
    ],
    [
        'slug' => 'product-discovery',
        'category' => 'product',
        'title' => 'Product Discovery and Customer Interviews',
        'summary' => 'Find out what people are trying to get done before you build.',
        'description' => 'Discovery as a discipline: understanding the job a customer is hiring your product for, compressing weeks of debate into a structured sprint, talking to users without leading them, and the standard a product has to reach before people love it.',
        'level' => 'intermediate',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 165,
        'lessons' => [
            ['The job a customer hires you for', 'The milkshake question, and why segmentation by demographic fails.', 'f84LymEs67Y', 300, true],
            ['The design sprint', 'Five days from open question to something a user can react to.', 'K2vSQPh6MCE', 600, false],
            ['How to talk to users', 'Asking about behaviour rather than opinions or intentions.', '9K8W5AGnvvI', 1500, false],
            ['How to build products users love', 'Setting the bar, and measuring whether you cleared it.', 'Th8JoIan4dg', 1980, false],
        ],
    ],
    [
        'slug' => 'communicating-product-decisions',
        'category' => 'product',
        'title' => 'Communicating Product Decisions',
        'summary' => 'Write and present so a decision survives the room.',
        'description' => 'A decision nobody understood is a decision nobody will follow. Narrative structure, presenting without hiding behind jargon, and building slides that support the point instead of replacing it.',
        'level' => 'beginner',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 140,
        'lessons' => [
            ['The science of storytelling', 'Why a narrative is remembered and a list of points is not.', 'Nj-hdQMa3uA', 900, true],
            ['Sounding clear instead of sounding clever', 'A gentle demolition of the habits that make talks empty.', '8S0FDjFBj8o', 350, false],
            ['How to avoid death by slides', 'Designing a deck that supports you rather than competes with you.', 'AykYRO5d_lI', 1140, false],
        ],
    ],
    [
        'slug' => 'focus-energy-and-burnout',
        'category' => 'wellbeing',
        'title' => 'Focus, Energy and Burnout Prevention',
        'summary' => 'Protecting attention and recovery over a long career.',
        'description' => 'Sustainable performance is a health question, not a willpower question. Short practices you can genuinely fit into a working day, a more useful relationship with stress, and the evidence on what sleep is doing for you.',
        'level' => 'beginner',
        'mandatory' => false,
        'passing_score' => 70,
        'certificate' => false,
        'published_days_ago' => 130,
        'lessons' => [
            ['A five minute reset', 'A short practice that fits between two meetings.', 'inpok4MKVLM', 372, true],
            ['Ten minutes for an anxious day', 'A longer guided practice for when the short one is not enough.', 'wfDTp2GogaQ', 636, false],
            ['Making stress your friend', 'How your beliefs about stress change what it does to you.', 'RcGyVTAoXEU', 870, false],
            ['Sleep is your superpower', 'What the research says about sleep, memory and judgement.', '5MuIMqhT8DM', 1157, false],
        ],
    ],
];

$catalogueAuthor = $employees['DF-0002'];

/** @var array<string, list<array{id: string, seconds: int}>> */
$lessonsBySlug = [];

foreach ($catalogue as $course) {
    $courseId = $identifier('course:' . $course['slug']);
    $publishedAt = $moment($course['published_days_ago']);

    $totalSeconds = array_sum(array_map(
        static fn (array $lesson): int => $lesson[3],
        $course['lessons']
    ));

    $insert('courses', [
        'id' => $courseId,
        'category_id' => $identifier('category:' . $course['category']),
        'title' => $course['title'],
        'slug' => $course['slug'],
        'summary' => $course['summary'],
        'description' => $course['description'],
        'thumbnail_url' => null,
        'level' => $course['level'],
        'estimated_minutes' => (int) ceil($totalSeconds / 60),
        'is_mandatory' => $course['mandatory'],
        'mandatory_for_department_id' => null,
        'mandatory_for_designation_id' => null,
        'passing_score' => $course['passing_score'],
        'certificate_enabled' => $course['certificate'],
        'published_at' => $publishedAt,
        'created_by' => $catalogueAuthor,
        'is_active' => true,
        'created_at' => $publishedAt,
        'updated_at' => $publishedAt,
    ], 'id');

    $lessonsBySlug[$course['slug']] = [];

    foreach ($course['lessons'] as $index => $lesson) {
        [$title, $description, $videoId, $seconds, $isPreview] = $lesson;

        $lessonId = $identifier('lesson:' . $course['slug'] . ':' . ($index + 1));
        $url = 'https://www.youtube.com/watch?v=' . $videoId;

        $insert('lessons', [
            'id' => $lessonId,
            'course_id' => $courseId,
            'title' => $title,
            'description' => $description,
            'video_url' => $url,
            // Extracted through the same helper the API uses, so seeded rows
            // and rows created by an administrator are identical in shape.
            'video_id' => Str::youtubeId($url) ?? $videoId,
            'duration_seconds' => $seconds,
            'sequence' => $index + 1,
            'is_preview' => $isPreview,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ], 'id');

        $lessonsBySlug[$course['slug']][] = ['id' => $lessonId, 'seconds' => $seconds];
    }
}

// ---------------------------------------------------------------------------
// Reference data: quizzes
// ---------------------------------------------------------------------------

$quizzes = [
    [
        'course' => 'information-security-essentials',
        'title' => 'Information Security Essentials assessment',
        'pass_percent' => 80,
        'max_attempts' => 3,
        'questions' => [
            [
                'Which of these makes a passphrase hardest to crack?',
                ['Replacing letters with lookalike symbols', 'Making it long and unpredictable', 'Adding a number at the end', 'Changing it every thirty days'],
                1,
                'Cracking cost grows with length and unpredictability. Symbol substitutions are in every wordlist, and forced rotation tends to produce weaker, patterned passwords.',
            ],
            [
                'A supplier emails an urgent invoice from an address you do not recognise. What do you do first?',
                ['Pay it to avoid delaying the supplier', 'Forward it to your manager to decide', 'Verify the request through a channel you already trust', 'Reply asking them to confirm it is genuine'],
                2,
                'Replying to the message only ever reaches whoever sent it. Confirmation has to travel over a channel the attacker does not control.',
            ],
            [
                'Why must an application never store passwords as plain text?',
                ['It uses more storage than hashing', 'A single database leak exposes every account, including reused ones elsewhere', 'It makes the login page slower', 'It prevents users from resetting their password'],
                1,
                'A plain text store turns one breach into a credential list that works against every other service where the password was reused.',
            ],
            [
                'What does the padlock in a browser address bar actually tell you?',
                ['The site is run by a legitimate business', 'The connection to that site is encrypted', 'The site has been checked for malware', 'Your data will not be sold'],
                1,
                'It attests to the transport, not the trustworthiness of the operator. A malicious site can hold a perfectly valid certificate.',
            ],
            [
                'You have accidentally sent a spreadsheet of employee data to the wrong address. What is the right first step?',
                ['Recall the message and say nothing further', 'Delete it from your sent items', 'Report it immediately so it can be contained', 'Wait to see whether anyone responds'],
                2,
                'Speed of containment decides how bad a data incident becomes. Reporting early is always treated as the correct action, never as the fault.',
            ],
        ],
    ],
    [
        'course' => 'git-and-version-control',
        'title' => 'Git fundamentals assessment',
        'pass_percent' => 70,
        'max_attempts' => 2,
        'questions' => [
            [
                'What does a Git commit actually record?',
                ['Only the lines that changed', 'A complete snapshot of the tracked tree at that moment', 'A patch file against the previous release', 'The contents of your working directory including ignored files'],
                1,
                'Git stores snapshots and deduplicates unchanged content. Diffs are calculated when you ask for them, not stored.',
            ],
            [
                'You want your feature branch to include the latest main without a merge commit. What do you use?',
                ['git merge main', 'git rebase main', 'git cherry-pick main', 'git reset --hard main'],
                1,
                'Rebase replays your commits on top of the new base, producing a linear history. Merge would record the join explicitly.',
            ],
            [
                'When is rebasing a branch a bad idea?',
                ['When the branch has more than ten commits', 'When other people have already pulled that branch', 'When the branch is older than a week', 'When there are merge conflicts'],
                1,
                'Rebasing rewrites commit identities. Doing that to shared history forces everyone else to reconcile a branch that no longer matches theirs.',
            ],
            [
                'You committed a change to the wrong branch but have not pushed. What is the safest fix?',
                ['Delete the repository and clone it again', 'git reset --hard and retype the change', 'Move the commit with git cherry-pick, then reset the wrong branch', 'Force push over the correct branch'],
                2,
                'Cherry-picking copies the work where it belongs before anything is discarded, so nothing depends on you having retyped it correctly.',
            ],
            [
                'What is git reflog useful for?',
                ['Listing every branch on the remote', 'Recovering commits that are no longer reachable from any branch', 'Showing who last changed each line of a file', 'Compressing the repository'],
                1,
                'The reflog records where HEAD has been, which is how you get back to work that a reset or a bad rebase appeared to destroy.',
            ],
        ],
    ],
];

/** @var array<string, array{id: string, questions: list<array{id: string, correct: int, options: int}>, pass: int}> */
$quizIndex = [];

foreach ($quizzes as $quiz) {
    $quizId = $identifier('quiz:' . $quiz['course']);
    $createdAt = $moment(200);

    $insert('quizzes', [
        'id' => $quizId,
        'course_id' => $identifier('course:' . $quiz['course']),
        'title' => $quiz['title'],
        'pass_percent' => $quiz['pass_percent'],
        'max_attempts' => $quiz['max_attempts'],
        'is_active' => true,
        'created_at' => $createdAt,
    ], 'id');

    $questionIndex = [];

    foreach ($quiz['questions'] as $position => $question) {
        [$text, $options, $correctIndex, $explanation] = $question;
        $questionId = $identifier('question:' . $quiz['course'] . ':' . ($position + 1));

        $insert('quiz_questions', [
            'id' => $questionId,
            'quiz_id' => $quizId,
            'question' => $text,
            'options' => $options,
            'correct_index' => $correctIndex,
            'explanation' => $explanation,
            'points' => 1,
            'sequence' => $position + 1,
            'created_at' => $createdAt,
        ], 'id');

        $questionIndex[] = [
            'id' => $questionId,
            'correct' => $correctIndex,
            'options' => count($options),
        ];
    }

    $quizIndex[$quiz['course']] = [
        'id' => $quizId,
        'questions' => $questionIndex,
        'pass' => $quiz['pass_percent'],
    ];
}

if (!Env::bool('SEED_DEMO_DATA', true)) {
    return;
}

// ---------------------------------------------------------------------------
// Demo data: enrolments and the watch history that justifies each percentage
// ---------------------------------------------------------------------------

$demoEnrolments = [
    // Annual security refresh, pushed out by the People team.
    ['information-security-essentials', 'DF-0006', 'completed', 4, 'DF-0002', -20, 52, 26],
    ['information-security-essentials', 'DF-0011', 'completed', 4, 'DF-0002', -15, 48, 31],
    ['information-security-essentials', 'DF-0005', 'in_progress', 3, 'DF-0002', 12, 30, null],
    ['information-security-essentials', 'DF-0003', 'in_progress', 3, 'DF-0002', 9, 27, null],
    ['information-security-essentials', 'DF-0007', 'in_progress', 2, 'DF-0002', 18, 21, null],
    ['information-security-essentials', 'DF-0008', 'not_started', 0, 'DF-0002', -6, 34, null],
    ['information-security-essentials', 'DF-0010', 'in_progress', 1, 'DF-0002', -3, 29, null],
    ['information-security-essentials', 'DF-0012', 'not_started', 0, 'DF-0002', 25, 12, null],

    ['respectful-workplace', 'DF-0006', 'completed', 4, 'DF-0002', -40, 70, 44],
    ['respectful-workplace', 'DF-0007', 'completed', 4, 'DF-0002', -40, 70, 52],
    ['respectful-workplace', 'DF-0008', 'in_progress', 1, 'DF-0002', 7, 25, null],
    ['respectful-workplace', 'DF-0009', 'not_started', 0, 'DF-0002', -11, 38, null],
    ['respectful-workplace', 'DF-0010', 'completed', 4, 'DF-0002', -30, 60, 39],
    ['respectful-workplace', 'DF-0012', 'not_started', 0, 'DF-0002', 21, 14, null],

    ['data-privacy-and-protection', 'DF-0004', 'completed', 3, 'DF-0003', -25, 55, 33],
    ['data-privacy-and-protection', 'DF-0002', 'completed', 3, 'DF-0001', -25, 55, 41],
    ['data-privacy-and-protection', 'DF-0006', 'in_progress', 1, 'DF-0003', 16, 18, null],
    ['data-privacy-and-protection', 'DF-0011', 'not_started', 0, 'DF-0003', -4, 26, null],

    ['welcome-to-dayflow', 'DF-0011', 'completed', 4, 'DF-0002', -60, 90, 78],
    ['welcome-to-dayflow', 'DF-0012', 'completed', 4, 'DF-0002', -60, 88, 74],
    ['welcome-to-dayflow', 'DF-0008', 'completed', 4, 'DF-0002', -70, 100, 92],
    ['welcome-to-dayflow', 'DF-0010', 'completed', 4, 'DF-0002', -70, 99, 88],
    ['welcome-to-dayflow', 'DF-0007', 'in_progress', 3, 'DF-0002', 5, 16, null],

    ['working-at-dayflow', 'DF-0011', 'in_progress', 2, 'DF-0002', 10, 20, null],
    ['working-at-dayflow', 'DF-0012', 'not_started', 0, 'DF-0002', 30, 9, null],
    ['working-at-dayflow', 'DF-0008', 'completed', 3, 'DF-0002', -55, 85, 70],

    // Voluntary technical study, mostly self-enrolled.
    ['git-and-version-control', 'DF-0006', 'completed', 5, null, null, 44, 17],
    ['git-and-version-control', 'DF-0007', 'in_progress', 3, null, null, 23, null],
    ['git-and-version-control', 'DF-0008', 'in_progress', 1, 'DF-0005', 20, 13, null],
    ['git-and-version-control', 'DF-0012', 'not_started', 0, null, null, 6, null],

    ['sql-for-everyday-analysis', 'DF-0010', 'in_progress', 2, null, null, 19, null],
    ['sql-for-everyday-analysis', 'DF-0004', 'completed', 5, null, null, 63, 35],
    ['sql-for-everyday-analysis', 'DF-0009', 'not_started', 0, null, null, 8, null],

    ['containers-and-docker', 'DF-0006', 'in_progress', 2, null, null, 15, null],
    ['containers-and-docker', 'DF-0007', 'not_started', 0, 'DF-0005', 28, 11, null],

    ['modern-javascript-foundations', 'DF-0007', 'in_progress', 4, null, null, 40, null],
    ['modern-javascript-foundations', 'DF-0012', 'in_progress', 1, null, null, 10, null],

    ['leading-without-authority', 'DF-0005', 'completed', 5, 'DF-0001', -18, 72, 28],
    ['leading-without-authority', 'DF-0009', 'in_progress', 2, 'DF-0001', 22, 33, null],

    ['coaching-conversations', 'DF-0005', 'in_progress', 1, null, null, 14, null],
    ['coaching-conversations', 'DF-0009', 'not_started', 0, 'DF-0001', 35, 7, null],
    ['coaching-conversations', 'DF-0002', 'completed', 4, null, null, 58, 30],

    ['product-discovery', 'DF-0012', 'in_progress', 2, null, null, 17, null],
    ['product-discovery', 'DF-0006', 'not_started', 0, null, null, 5, null],

    ['communicating-product-decisions', 'DF-0012', 'completed', 3, null, null, 50, 22],
    ['communicating-product-decisions', 'DF-0011', 'in_progress', 1, null, null, 12, null],

    ['focus-energy-and-burnout', 'DF-0006', 'completed', 4, null, null, 36, 13],
    ['focus-energy-and-burnout', 'DF-0008', 'in_progress', 2, null, null, 21, null],
    ['focus-energy-and-burnout', 'DF-0010', 'not_started', 0, null, null, 4, null],
    ['focus-energy-and-burnout', 'DF-0003', 'completed', 4, null, null, 42, 19],
];

// The rows above are the ones a demonstration walks through, so they are
// written out. Everybody else in the company also has to be enrolled on the
// mandatory courses, or the training compliance figure is computed over twelve
// people and reported as the company's - which is what it used to do.
$mandatorySlugs = [
    'information-security-essentials',
    'respectful-workplace',
    'data-privacy-and-protection',
    'welcome-to-dayflow',
    'working-at-dayflow',
];

$optionalByDepartment = [
    'engineering' => ['git-and-version-control', 'containers-and-docker', 'modern-javascript-foundations'],
    'design' => ['product-discovery', 'communicating-product-decisions'],
    'sales' => ['sql-for-everyday-analysis', 'communicating-product-decisions'],
    'marketing' => ['communicating-product-decisions', 'product-discovery'],
    'success' => ['focus-energy-and-burnout', 'communicating-product-decisions'],
    'finance' => ['sql-for-everyday-analysis', 'focus-energy-and-burnout'],
    'people' => ['coaching-conversations', 'focus-energy-and-burnout'],
    'executive' => ['leading-without-authority', 'coaching-conversations'],
];

// Already spoken for by a hand-written row above.
$claimed = [];
foreach ($demoEnrolments as $row) {
    $claimed[$row[0] . ':' . $row[1]] = true;
}

foreach (DemoCohort::extended() as $person) {
    if ($person['exit_date'] !== null) {
        continue;
    }

    $code = $person['code'];
    $serial = (int) substr($code, 3);

    // Mandatory training for everyone, at a mix of stages: a company where
    // every single person has finished is as unconvincing as one where nobody
    // has, and the compliance report exists to show the difference.
    foreach ($mandatorySlugs as $slot => $slug) {
        if (isset($claimed[$slug . ':' . $code]) || !isset($lessonsBySlug[$slug])) {
            continue;
        }

        $total = count($lessonsBySlug[$slug]);
        $roll = ($serial * 7 + $slot * 11) % 10;

        if ($roll < 6) {
            $status = 'completed';
            $completed = $total;
            $finished = 5 + (($serial + $slot) % 60);
        } elseif ($roll < 9) {
            $status = 'in_progress';
            $completed = max(1, intdiv($total, 2));
            $finished = null;
        } else {
            $status = 'not_started';
            $completed = 0;
            $finished = null;
        }

        $demoEnrolments[] = [
            $slug,
            $code,
            $status,
            $completed,
            'DF-0002',
            // Due dates straddle today, so the overdue count is not always nil.
            $status === 'completed' ? -(10 + $slot) : (($roll % 3) === 0 ? -(3 + $slot) : (7 + $slot * 4)),
            20 + (($serial * 3 + $slot) % 70),
            $finished,
        ];
    }

    // One or two optional courses, chosen by department.
    $optional = $optionalByDepartment[$person['department']] ?? [];

    foreach (array_slice($optional, 0, 1 + ($serial % 2)) as $slot => $slug) {
        if (isset($claimed[$slug . ':' . $code]) || !isset($lessonsBySlug[$slug])) {
            continue;
        }

        $total = count($lessonsBySlug[$slug]);
        $roll = ($serial * 5 + $slot * 3) % 10;

        $demoEnrolments[] = [
            $slug,
            $code,
            $roll < 4 ? 'completed' : ($roll < 8 ? 'in_progress' : 'not_started'),
            $roll < 4 ? $total : ($roll < 8 ? max(1, intdiv($total, 3)) : 0),
            null,
            null,
            10 + (($serial * 2 + $slot) % 50),
            $roll < 4 ? 4 + (($serial + $slot) % 30) : null,
        ];
    }
}

/** @var array<string, string> Course slug and employee code mapped to enrolment id. */
$enrolmentIds = [];

foreach ($demoEnrolments as $row) {
    [$slug, $code, $status, $completedLessons, $assignedByCode, $dueOffset, $age, $finishedDaysAgo] = $row;

    $lessons = $lessonsBySlug[$slug];
    $total = count($lessons);
    $employeeId = $employees[$code];
    $enrolmentKey = $slug . ':' . $code;
    $enrolmentId = $identifier('enrolment:' . $enrolmentKey);
    $enrolmentIds[$enrolmentKey] = $enrolmentId;

    $createdAt = $moment($age);
    $startedAt = $status === 'not_started' ? null : $moment(max(1, $age - 2));
    $completedAt = $status === 'completed' ? $moment((int) $finishedDaysAgo) : null;

    // The stored percentage is exactly what the service would recompute from
    // the lesson_progress rows written below, so demo data and live data
    // behave identically on every screen.
    $progressPercent = $status === 'completed'
        ? 100
        : ($total === 0 ? 0 : (int) round(($completedLessons / $total) * 100));

    // The last lesson touched is the one part way through, when there is one.
    $lastLessonIndex = $completedLessons < $total ? $completedLessons : $total - 1;
    $lastLessonId = $completedLessons === 0 ? null : $lessons[$lastLessonIndex]['id'];

    $insert('enrolments', [
        'id' => $enrolmentId,
        'course_id' => $identifier('course:' . $slug),
        'employee_id' => $employeeId,
        'assigned_by' => $assignedByCode === null ? null : $employees[$assignedByCode],
        'assigned_at' => $assignedByCode === null ? null : $createdAt,
        'due_on' => $dueOffset === null ? null : $calendarDay((int) $dueOffset),
        'started_at' => $startedAt,
        'completed_at' => $completedAt,
        'status' => $status,
        'progress_percent' => $progressPercent,
        'last_lesson_id' => $lastLessonId,
        'created_at' => $createdAt,
        'updated_at' => $completedAt ?? $moment(max(1, (int) floor($age / 4))),
        // The arbiter has to be the natural key, not the primary key. An
        // ON CONFLICT clause only silences the index it names: enrolments also
        // carries a unique index on (course_id, employee_id), and a re-run hit
        // that one first and raised, aborting the seed.
    ], 'course_id, employee_id');

    // Finished lessons, oldest first, ending on the day the course was closed.
    $latestCompletion = $status === 'completed' ? (int) $finishedDaysAgo : max(1, (int) floor($age / 4));

    for ($index = 0; $index < $completedLessons; $index++) {
        $daysAgo = $latestCompletion + ($completedLessons - 1 - $index);
        $watchedAt = $moment($daysAgo);

        $insert('lesson_progress', [
            'id' => $identifier('progress:' . $enrolmentKey . ':' . $index),
            'enrolment_id' => $enrolmentId,
            'lesson_id' => $lessons[$index]['id'],
            'employee_id' => $employeeId,
            'watched_seconds' => $lessons[$index]['seconds'],
            'completed_at' => $watchedAt,
            'created_at' => $watchedAt,
            'updated_at' => $watchedAt,
        ], 'id');
    }

    // Whoever is mid-course is part way through the next lesson, short of the
    // ninety percent mark that would have completed it.
    if ($status === 'in_progress' && $completedLessons < $total) {
        $partialAt = $moment(max(1, $latestCompletion - 1));

        $insert('lesson_progress', [
            'id' => $identifier('progress:' . $enrolmentKey . ':' . $completedLessons),
            'enrolment_id' => $enrolmentId,
            'lesson_id' => $lessons[$completedLessons]['id'],
            'employee_id' => $employeeId,
            'watched_seconds' => (int) floor($lessons[$completedLessons]['seconds'] * 0.42),
            'completed_at' => null,
            'created_at' => $partialAt,
            'updated_at' => $partialAt,
        ], 'id');
    }
}

// ---------------------------------------------------------------------------
// Demo data: quiz attempts
// ---------------------------------------------------------------------------

$demoAttempts = [
    // course slug, employee code, attempt number, correct answers, days ago
    ['information-security-essentials', 'DF-0006', 1, 5, 26],
    ['information-security-essentials', 'DF-0011', 1, 3, 34],
    ['information-security-essentials', 'DF-0011', 2, 4, 31],
    ['information-security-essentials', 'DF-0003', 1, 3, 5],
    ['git-and-version-control', 'DF-0006', 1, 4, 17],
    ['git-and-version-control', 'DF-0007', 1, 2, 6],
];

foreach ($demoAttempts as [$slug, $code, $attemptNumber, $correctCount, $daysAgo]) {
    $quiz = $quizIndex[$slug];
    $enrolmentKey = $slug . ':' . $code;

    if (!isset($enrolmentIds[$enrolmentKey])) {
        continue;
    }

    $answers = [];
    foreach ($quiz['questions'] as $position => $question) {
        $isCorrect = $position < $correctCount;

        $answers[] = [
            'question_id' => $question['id'],
            // A wrong answer picks the option after the correct one, so the
            // stored submission genuinely produces the recorded score.
            'selected_index' => $isCorrect
                ? $question['correct']
                : ($question['correct'] + 1) % $question['options'],
            'correct' => $isCorrect,
            'points' => $isCorrect ? 1 : 0,
        ];
    }

    $questionCount = count($quiz['questions']);
    $score = $questionCount === 0 ? 0 : (int) round(($correctCount / $questionCount) * 100);
    $submittedAt = $moment((int) $daysAgo);

    $insert('quiz_attempts', [
        'id' => $identifier('attempt:' . $enrolmentKey . ':' . $attemptNumber),
        'quiz_id' => $quiz['id'],
        'enrolment_id' => $enrolmentIds[$enrolmentKey],
        'employee_id' => $employees[$code],
        'answers' => $answers,
        'score_percent' => $score,
        'passed' => $score >= $quiz['pass'],
        'started_at' => $now->modify(sprintf('-%d days', (int) $daysAgo))->modify('-18 minutes')->format(\DateTimeInterface::ATOM),
        'submitted_at' => $submittedAt,
        'attempt_number' => $attemptNumber,
        'created_at' => $submittedAt,
    ], 'id');
}

// ---------------------------------------------------------------------------
// Demo data: issued certificates
//
// Only the two courses with certificate_enabled produce one, and only for the
// people whose passing attempt is recorded above.
// ---------------------------------------------------------------------------

$demoCertificates = [
    ['information-security-essentials', 'DF-0006', 'DF-LRN-2026-4C1A9E07', 100, 26],
    ['information-security-essentials', 'DF-0011', 'DF-LRN-2026-8B27D6F3', 80, 31],
    ['git-and-version-control', 'DF-0006', 'DF-LRN-2026-15E9A4B8', 80, 17],
];

foreach ($demoCertificates as [$slug, $code, $number, $score, $daysAgo]) {
    $enrolmentKey = $slug . ':' . $code;

    if (!isset($enrolmentIds[$enrolmentKey])) {
        continue;
    }

    $insert('certificates', [
        'id' => $identifier('certificate:' . $enrolmentKey),
        'enrolment_id' => $enrolmentIds[$enrolmentKey],
        'employee_id' => $employees[$code],
        'course_id' => $identifier('course:' . $slug),
        'certificate_number' => $number,
        'issued_on' => $calendarDay(-((int) $daysAgo)),
        'score_percent' => $score,
        'created_at' => $moment((int) $daysAgo),
    ], 'id');
}
