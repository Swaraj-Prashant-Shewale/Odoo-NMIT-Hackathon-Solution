-- ---------------------------------------------------------------------------
-- Enrolments and per-lesson watch progress.
--
-- progress_percent is a derived value kept on the enrolment so the catalogue
-- and dashboard never have to aggregate lesson_progress for every row. It is
-- recomputed from lesson_progress whenever either side changes.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS enrolments (
    id                UUID PRIMARY KEY,
    course_id         UUID        NOT NULL REFERENCES courses (id) ON DELETE CASCADE,
    employee_id       UUID        NOT NULL,
    assigned_by       UUID,
    assigned_at       TIMESTAMPTZ,
    due_on            DATE,
    started_at        TIMESTAMPTZ,
    completed_at      TIMESTAMPTZ,
    status            TEXT        NOT NULL DEFAULT 'not_started'
                      CHECK (status IN ('not_started','in_progress','completed','expired')),
    progress_percent  INTEGER     NOT NULL DEFAULT 0
                      CHECK (progress_percent BETWEEN 0 AND 100),
    last_lesson_id    UUID        REFERENCES lessons (id) ON DELETE SET NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT enrolments_course_employee_unique UNIQUE (course_id, employee_id),
    CONSTRAINT enrolments_completion_consistent
        CHECK ((status = 'completed') = (completed_at IS NOT NULL))
);

CREATE INDEX IF NOT EXISTS enrolments_employee_idx ON enrolments (employee_id, status);
CREATE INDEX IF NOT EXISTS enrolments_course_idx   ON enrolments (course_id, status);
CREATE INDEX IF NOT EXISTS enrolments_due_idx      ON enrolments (due_on)
    WHERE due_on IS NOT NULL AND status <> 'completed';

CREATE TABLE IF NOT EXISTS lesson_progress (
    id               UUID PRIMARY KEY,
    enrolment_id     UUID        NOT NULL REFERENCES enrolments (id) ON DELETE CASCADE,
    lesson_id        UUID        NOT NULL REFERENCES lessons (id) ON DELETE CASCADE,
    employee_id      UUID        NOT NULL,
    watched_seconds  INTEGER     NOT NULL DEFAULT 0 CHECK (watched_seconds >= 0),
    completed_at     TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT lesson_progress_enrolment_lesson_unique UNIQUE (enrolment_id, lesson_id)
);

CREATE INDEX IF NOT EXISTS lesson_progress_employee_idx ON lesson_progress (employee_id, updated_at DESC);
CREATE INDEX IF NOT EXISTS lesson_progress_lesson_idx   ON lesson_progress (lesson_id);
