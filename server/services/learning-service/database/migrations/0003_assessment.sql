-- ---------------------------------------------------------------------------
-- Assessment: quizzes, graded attempts and the certificates they unlock.
--
-- correct_index and explanation live only in this schema. Neither is ever put
-- into the payload that renders the quiz, so the answer key cannot be read out
-- of the network tab before an attempt is submitted.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS quizzes (
    id            UUID PRIMARY KEY,
    course_id     UUID        NOT NULL REFERENCES courses (id) ON DELETE CASCADE,
    title         TEXT        NOT NULL,
    pass_percent  INTEGER     NOT NULL DEFAULT 70 CHECK (pass_percent BETWEEN 0 AND 100),
    max_attempts  INTEGER     NOT NULL DEFAULT 3 CHECK (max_attempts > 0),
    is_active     BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- A course presents exactly one quiz at a time; retired quizzes stay for their
-- attempt history, so the uniqueness is scoped to the active one.
CREATE UNIQUE INDEX IF NOT EXISTS quizzes_active_course_idx
    ON quizzes (course_id) WHERE is_active;

CREATE TABLE IF NOT EXISTS quiz_questions (
    id             UUID PRIMARY KEY,
    quiz_id        UUID        NOT NULL REFERENCES quizzes (id) ON DELETE CASCADE,
    question       TEXT        NOT NULL,
    options        JSONB       NOT NULL,
    correct_index  INTEGER     NOT NULL CHECK (correct_index >= 0),
    explanation    TEXT,
    points         INTEGER     NOT NULL DEFAULT 1 CHECK (points > 0),
    sequence       INTEGER     NOT NULL DEFAULT 1 CHECK (sequence > 0),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT quiz_questions_options_are_a_list CHECK (jsonb_typeof(options) = 'array'),
    -- The CASE keeps jsonb_array_length off a non-array value: constraint
    -- evaluation order is not guaranteed, and a raised type error would mask
    -- the real reason the row was rejected.
    CONSTRAINT quiz_questions_correct_index_in_range
        CHECK (correct_index < jsonb_array_length(
            CASE WHEN jsonb_typeof(options) = 'array' THEN options ELSE '[]'::jsonb END
        ))
);

CREATE INDEX IF NOT EXISTS quiz_questions_quiz_idx ON quiz_questions (quiz_id, sequence);

CREATE TABLE IF NOT EXISTS quiz_attempts (
    id              UUID PRIMARY KEY,
    quiz_id         UUID        NOT NULL REFERENCES quizzes (id) ON DELETE CASCADE,
    enrolment_id    UUID        NOT NULL REFERENCES enrolments (id) ON DELETE CASCADE,
    employee_id     UUID        NOT NULL,
    answers         JSONB       NOT NULL DEFAULT '[]'::jsonb,
    score_percent   INTEGER     NOT NULL DEFAULT 0 CHECK (score_percent BETWEEN 0 AND 100),
    passed          BOOLEAN     NOT NULL DEFAULT FALSE,
    started_at      TIMESTAMPTZ,
    submitted_at    TIMESTAMPTZ,
    attempt_number  INTEGER     NOT NULL DEFAULT 1 CHECK (attempt_number > 0),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT quiz_attempts_number_unique UNIQUE (quiz_id, enrolment_id, attempt_number)
);

CREATE INDEX IF NOT EXISTS quiz_attempts_enrolment_idx ON quiz_attempts (enrolment_id, attempt_number);
CREATE INDEX IF NOT EXISTS quiz_attempts_employee_idx  ON quiz_attempts (employee_id, submitted_at DESC);

CREATE TABLE IF NOT EXISTS certificates (
    id                  UUID PRIMARY KEY,
    enrolment_id        UUID        NOT NULL REFERENCES enrolments (id) ON DELETE CASCADE,
    employee_id         UUID        NOT NULL,
    course_id           UUID        NOT NULL REFERENCES courses (id) ON DELETE CASCADE,
    certificate_number  TEXT        NOT NULL UNIQUE,
    issued_on           DATE        NOT NULL,
    score_percent       INTEGER     NOT NULL DEFAULT 0
                        CHECK (score_percent BETWEEN 0 AND 100),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- One certificate per completed enrolment; re-running completion must not
    -- mint a second document for the same achievement.
    CONSTRAINT certificates_enrolment_unique UNIQUE (enrolment_id)
);

CREATE INDEX IF NOT EXISTS certificates_employee_idx ON certificates (employee_id, issued_on DESC);
