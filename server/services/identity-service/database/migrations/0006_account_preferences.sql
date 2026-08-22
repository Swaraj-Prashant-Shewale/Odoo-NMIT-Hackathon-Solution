-- ---------------------------------------------------------------------------
-- What an account holds about itself, as opposed to what HR holds about the
-- person.
--
-- These belong here rather than on the employee record for two reasons. An
-- account can exist before HR has created the person behind it - somebody who
-- signed up this morning has no employee row at all - and they would have
-- nowhere to put a display picture or a theme. And they are preferences of the
-- account, not facts about the employee: which one of them is looking at a
-- dark screen is nobody's HR record.
-- ---------------------------------------------------------------------------

ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path TEXT;

-- "system" follows whatever the operating system asks for, which is the only
-- honest default: the alternative is guessing.
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme TEXT NOT NULL DEFAULT 'system';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_theme_known'
    ) THEN
        ALTER TABLE users
            ADD CONSTRAINT users_theme_known CHECK (theme IN ('system', 'light', 'dark'));
    END IF;
END
$$;
