-- Circle Phase 3: edit + delete pesan zone.
-- edited_at terisi setiap kali teks pesan diubah (penanda
-- "(diedit)" di frontend); deleted_at adalah soft delete —
-- baris tetap ada tapi tidak pernah ditampilkan lagi, mengikuti
-- pola soft delete konten lain di proyek ini.
ALTER TABLE zone_messages
  ADD COLUMN edited_at DATETIME NULL DEFAULT NULL AFTER timestamp,
  ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER edited_at;
