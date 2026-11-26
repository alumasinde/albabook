-- Remember-token reuse detection (auth.md §8a). Rotation now appends a
-- successor row instead of overwriting the hash, and the spent row keeps a
-- used_at stamp: a cookie presented again after that is a replay, not a
-- first use.

ALTER TABLE remember_tokens
    ADD COLUMN used_at DATETIME NULL AFTER revoked_at;
