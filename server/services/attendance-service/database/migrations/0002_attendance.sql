-- ---------------------------------------------------------------------------
-- Attendance: an immutable punch trail plus one rollup row per working day.
--
-- attendance_punches is the evidence and is never edited. attendance_records
-- is the derived daily summary, which HR may correct and a regularisation may
-- amend. Keeping the two apart means a correction can always be compared
-- against what the clock actually recorded.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS attendance_records (
    id                  UUID PRIMARY KEY,
    employee_id         UUID        NOT NULL,
    work_date           DATE        NOT NULL,
    shift_id            UUID        REFERENCES shifts (id),
    check_in_at         TIMESTAMPTZ,
    check_out_at        TIMESTAMPTZ,
    check_in_ip         TEXT,
    check_out_ip        TEXT,
    check_in_source     TEXT        NOT NULL DEFAULT 'web',
    worked_seconds      INTEGER     NOT NULL DEFAULT 0,
    break_seconds       INTEGER     NOT NULL DEFAULT 0,
    overtime_seconds    INTEGER     NOT NULL DEFAULT 0,
    late_minutes        INTEGER     NOT NULL DEFAULT 0,
    early_leave_minutes INTEGER     NOT NULL DEFAULT 0,
    status              TEXT        NOT NULL DEFAULT 'absent',
    is_regularised      BOOLEAN     NOT NULL DEFAULT FALSE,
    remarks             TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT attendance_records_employee_date_unique UNIQUE (employee_id, work_date),
    CONSTRAINT attendance_records_status_known CHECK (
        status IN ('present', 'absent', 'half_day', 'on_leave', 'holiday', 'weekly_off', 'wfh')
    ),
    CONSTRAINT attendance_records_source_known CHECK (
        check_in_source IN ('web', 'mobile', 'biometric', 'import', 'manual')
    ),
    CONSTRAINT attendance_records_out_after_in CHECK (
        check_out_at IS NULL OR check_in_at IS NULL OR check_out_at >= check_in_at
    ),
    CONSTRAINT attendance_records_durations_positive CHECK (
        worked_seconds >= 0 AND break_seconds >= 0 AND overtime_seconds >= 0
            AND late_minutes >= 0 AND early_leave_minutes >= 0
    )
);

CREATE INDEX IF NOT EXISTS attendance_records_employee_idx
    ON attendance_records (employee_id, work_date DESC);

CREATE INDEX IF NOT EXISTS attendance_records_date_idx
    ON attendance_records (work_date, status);

-- The live board asks "who is still checked in?" on every refresh, so the
-- open rows are indexed on their own rather than scanned out of the whole table.
CREATE INDEX IF NOT EXISTS attendance_records_open_idx
    ON attendance_records (employee_id, work_date DESC)
    WHERE check_out_at IS NULL AND check_in_at IS NOT NULL;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS attendance_punches (
    id                   UUID PRIMARY KEY,
    attendance_record_id UUID         NOT NULL REFERENCES attendance_records (id) ON DELETE CASCADE,
    employee_id          UUID         NOT NULL,
    punched_at           TIMESTAMPTZ  NOT NULL,
    direction            TEXT         NOT NULL,
    ip_address           TEXT,
    user_agent           TEXT,
    source               TEXT         NOT NULL DEFAULT 'web',
    latitude             NUMERIC(9,6),
    longitude            NUMERIC(9,6),
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT attendance_punches_direction_known CHECK (direction IN ('in', 'out')),
    CONSTRAINT attendance_punches_source_known CHECK (
        source IN ('web', 'mobile', 'biometric', 'import', 'manual')
    ),
    CONSTRAINT attendance_punches_latitude_range  CHECK (latitude  IS NULL OR latitude  BETWEEN -90 AND 90),
    CONSTRAINT attendance_punches_longitude_range CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)
);

CREATE INDEX IF NOT EXISTS attendance_punches_record_idx
    ON attendance_punches (attendance_record_id, punched_at);

CREATE INDEX IF NOT EXISTS attendance_punches_employee_idx
    ON attendance_punches (employee_id, punched_at DESC);
