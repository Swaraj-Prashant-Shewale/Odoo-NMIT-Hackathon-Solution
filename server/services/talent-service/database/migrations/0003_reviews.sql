-- ---------------------------------------------------------------------------
-- Appraisals.
--
-- A review always has two people: employee_id is the person being reviewed and
-- reviewer_id is the person writing it. Everything the API exposes hangs off
-- that distinction, so the pair is constrained here as well as in code:
-- a self review can only ever be written by its own subject.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS review_participants (
    id               UUID PRIMARY KEY,
    review_cycle_id  UUID        NOT NULL REFERENCES review_cycles (id) ON DELETE CASCADE,
    employee_id      UUID        NOT NULL,
    manager_id       UUID,
    status           TEXT        NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending', 'in_progress', 'completed')),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT review_participants_unique UNIQUE (review_cycle_id, employee_id)
);

CREATE INDEX IF NOT EXISTS review_participants_employee_idx
    ON review_participants (employee_id);

CREATE INDEX IF NOT EXISTS review_participants_manager_idx
    ON review_participants (manager_id, review_cycle_id);

CREATE TABLE IF NOT EXISTS reviews (
    id                UUID PRIMARY KEY,
    review_cycle_id   UUID          NOT NULL REFERENCES review_cycles (id) ON DELETE CASCADE,
    employee_id       UUID          NOT NULL,
    reviewer_id       UUID          NOT NULL,
    review_type       TEXT          NOT NULL
                      CHECK (review_type IN ('self', 'manager', 'peer', 'skip_level')),
    overall_rating    NUMERIC(4,2)  CHECK (overall_rating IS NULL OR overall_rating >= 0),
    strengths         TEXT,
    improvements      TEXT,
    achievements      TEXT,
    manager_comments  TEXT,
    employee_comments TEXT,
    -- Set on a peer review whose author asked not to be named. The row still
    -- records reviewer_id so an administrator can trace abuse; the API is what
    -- withholds it from everyone else.
    is_anonymous      BOOLEAN       NOT NULL DEFAULT FALSE,
    status            TEXT          NOT NULL DEFAULT 'draft'
                      CHECK (status IN ('draft', 'submitted', 'acknowledged')),
    submitted_at      TIMESTAMPTZ,
    acknowledged_at   TIMESTAMPTZ,
    created_at        TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ   NOT NULL DEFAULT NOW(),

    CONSTRAINT reviews_one_per_reviewer
        UNIQUE (review_cycle_id, employee_id, reviewer_id, review_type),
    CONSTRAINT reviews_self_written_by_subject
        CHECK (review_type <> 'self' OR employee_id = reviewer_id),
    CONSTRAINT reviews_anonymous_is_peer_only
        CHECK (is_anonymous = FALSE OR review_type = 'peer'),
    CONSTRAINT reviews_submitted_has_timestamp
        CHECK (status = 'draft' OR submitted_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS reviews_reviewer_idx
    ON reviews (reviewer_id, status);

CREATE INDEX IF NOT EXISTS reviews_subject_idx
    ON reviews (employee_id, submitted_at DESC);

CREATE INDEX IF NOT EXISTS reviews_cycle_idx
    ON reviews (review_cycle_id, review_type, status);

CREATE TABLE IF NOT EXISTS review_ratings (
    id             UUID PRIMARY KEY,
    review_id      UUID         NOT NULL REFERENCES reviews (id) ON DELETE CASCADE,
    competency_id  UUID         NOT NULL REFERENCES competencies (id),
    rating         NUMERIC(4,2) NOT NULL CHECK (rating >= 0),
    comment        TEXT,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT review_ratings_one_per_competency UNIQUE (review_id, competency_id)
);

CREATE INDEX IF NOT EXISTS review_ratings_review_idx
    ON review_ratings (review_id);

CREATE INDEX IF NOT EXISTS review_ratings_competency_idx
    ON review_ratings (competency_id);
