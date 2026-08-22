-- ---------------------------------------------------------------------------
-- Learning catalogue: categories, courses and the video lessons inside them.
--
-- Lesson content is a YouTube video embedded in the platform. Both the URL an
-- administrator pasted and the extracted eleven character video id are stored:
-- the URL keeps the original intent auditable, while the id is the only value
-- ever handed to the client, so an arbitrary attacker supplied address can
-- never reach an iframe src.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS course_categories (
    id             UUID PRIMARY KEY,
    name           TEXT        NOT NULL,
    slug           TEXT        NOT NULL UNIQUE,
    description    TEXT,
    icon           TEXT,
    colour         TEXT,
    display_order  INTEGER     NOT NULL DEFAULT 0,
    is_active      BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS course_categories_order_idx
    ON course_categories (display_order, name);

CREATE TABLE IF NOT EXISTS courses (
    id                            UUID PRIMARY KEY,
    category_id                   UUID        NOT NULL REFERENCES course_categories (id),
    title                         TEXT        NOT NULL,
    slug                          TEXT        NOT NULL UNIQUE,
    summary                       TEXT,
    description                   TEXT,
    thumbnail_url                 TEXT,
    level                         TEXT        NOT NULL DEFAULT 'beginner'
                                  CHECK (level IN ('beginner','intermediate','advanced')),
    estimated_minutes             INTEGER     NOT NULL DEFAULT 0 CHECK (estimated_minutes >= 0),
    is_mandatory                  BOOLEAN     NOT NULL DEFAULT FALSE,
    -- Cross-service references carry no foreign key: the department and
    -- designation tables live in a schema this role cannot see.
    mandatory_for_department_id   UUID,
    mandatory_for_designation_id  UUID,
    passing_score                 INTEGER     NOT NULL DEFAULT 70
                                  CHECK (passing_score BETWEEN 0 AND 100),
    certificate_enabled           BOOLEAN     NOT NULL DEFAULT FALSE,
    published_at                  TIMESTAMPTZ,
    created_by                    UUID,
    is_active                     BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS courses_category_idx    ON courses (category_id, title);
CREATE INDEX IF NOT EXISTS courses_level_idx       ON courses (level);
CREATE INDEX IF NOT EXISTS courses_active_idx      ON courses (is_active, published_at DESC);
CREATE INDEX IF NOT EXISTS courses_mandatory_idx   ON courses (is_mandatory) WHERE is_mandatory;

CREATE TABLE IF NOT EXISTS lessons (
    id                UUID PRIMARY KEY,
    course_id         UUID        NOT NULL REFERENCES courses (id) ON DELETE CASCADE,
    title             TEXT        NOT NULL,
    description       TEXT,
    video_url         TEXT        NOT NULL,
    video_id          TEXT        NOT NULL CHECK (video_id ~ '^[A-Za-z0-9_-]{11}$'),
    duration_seconds  INTEGER     NOT NULL DEFAULT 0 CHECK (duration_seconds >= 0),
    sequence          INTEGER     NOT NULL DEFAULT 1 CHECK (sequence > 0),
    is_preview        BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT lessons_course_sequence_unique UNIQUE (course_id, sequence)
);

CREATE INDEX IF NOT EXISTS lessons_course_idx ON lessons (course_id, sequence);
