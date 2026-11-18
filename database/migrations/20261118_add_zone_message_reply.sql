-- Message replies in Circle zones (2026-11-18, Phase 3 slice):
-- a zone message can quote one earlier message in the same zone.
-- ON DELETE SET NULL: if the original ever goes away, the reply
-- keeps its text and simply loses the quote.
ALTER TABLE zone_messages
    ADD COLUMN reply_to_id VARCHAR(36) NULL AFTER message,
    ADD KEY idx_zone_messages_reply_to_id (reply_to_id),
    ADD CONSTRAINT fk_zone_messages_reply
        FOREIGN KEY (reply_to_id) REFERENCES zone_messages(id)
        ON DELETE SET NULL ON UPDATE CASCADE;
