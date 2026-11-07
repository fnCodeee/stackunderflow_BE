# AUDIT: Modul Collaboration (PHP Native) — Stack Underflow
Tanggal: 2026-10-30 · Auditor: Hermes (Agnes-3.0-flash) · Repo: backend-php

## 1. Ringkasan Arsitektur Collaboration

- Alur request: `public/index.php` (front controller: parse JSON body ke `$GLOBALS['body']`, load routes) → `Router::dispatch()` (static routes diprioritaskan sebelum dynamic) → `AuthMiddleware::handle()` (JWT Bearer → `$GLOBALS['user']`) → `CollabController` (validasi + orkestrasi transaksi) → `CollabModel` (DAL untuk `collaborations`, `collab_media`, `collab_skills`, `circle`).
- **Tidak ada modul Application di PHP**: `collaboration_applications` ada di schema (enum `pending/accepted/rejected`), tapi tidak ada controller, model, route, atau query PHP yang menyentuhnya. Konsekuensi: `user_collaboration` tidak pernah terisi oleh kode → `accepted_member_count` selalu 0 → `available_slots` selalu = `required_member`.
- ID semua entitas = UUID v4 (VARCHAR(36)); baseline Express memakai Mongo ObjectId — field di response sengaja digandakan snake_case + camelCase di `getByUserId()` untuk kompatibilitas.
- Setiap create otomatis membuat 1 `circle` (1:1, unique `collaboration_id`) dalam transaksi yang sama.

## 2. Peta File dan Dependensi

| File | Peran |
|---|---|
| `public/index.php` | entry point, parse body JSON |
| `routes/Router.php` | router + dispatch |
| `routes/api.php` | daftar route collab (baris 84–95) |
| `middlewares/AuthMiddleware.php` | JWT auth (403 tanpa token, 401 invalid) |
| `controllers/CollabController.php` | createCollab, showCollab, getCollabDetail, yourCollabPost, updateCollab, deleteCollab |
| `models/CollabModel.php` | create, findById, getAll, findDetailById, getByUserId, delete/deleteById, generateUuid |
| `models/CircleModel.php` | create, findByCollaborationId, updateTitleByCollaborationId, dst. |
| `utils/JWT.php`, `utils/Response.php`, `config/database.php` (getDB singleton) | |
| `database/migrations/20261018_create_collaboration_tables.sql`, `20261019_create_circle_table.sql` | |

## 3. Daftar Endpoint Aktual (dari `routes/api.php`, terverifikasi live)

Semua collab endpoint memakai `AuthMiddleware` (Bearer JWT wajib; 403 jika token hilang, 401 jika invalid/expired).

| Method | Endpoint | Auth | Payload | Response sukses | Fungsi |
|---|---|---|---|---|---|
| POST | `/api/collab` | wajib | JSON body | 200 + detail collab baru | Create + auto-create circle |
| GET | `/api/collab` | wajib | — | 200 + array list (tanpa media/desc) | List semua |
| GET | `/api/collab/:id` | wajib | — | 200 + detail penuh / 404 | Detail |
| GET | `/api/profiles/:userId/collab` | wajib | — | 200 + array collab milik user / 404 | Collab milik user tertentu (hanya yang = owner) |
| PUT | `/api/collab/:id` | wajib | JSON parsial | 200 + data terbaru / 400, 403, 404 | Update (owner only) |
| DELETE | `/api/collab/:id` | wajib | — | 200 + data sebelum dihapus / 403, 404 | Hapus (owner only) |

## 4. Kontrak Request/Response (faktual, terverifikasi)

**POST /api/collab** — wajib: `title` (string, tidak boleh kosong), `description` (string), `required_member`/`requiredMember` (int ≥ 1), `skills_needed`/`skillsNeeded` (array non-kosong, string UUID atau object `{id}`; semua harus ada di tabel `skills`). Opsional: `media_urls` + `media_public_ids` (jika keduanya dikirim, count harus sama). Gagal: 400 `Seluruh Kolom wajib diisi.` / `not found` / `Count of mediaUrls and mediaPublicIds must be same!`. Response 200 `data` = object detail collab (`id`, `user_id`, `title`, `description`, `required_member`, `status`, `media_urls[]`, `media_public_ids[]`, `skills_needed[{id,skill_name,scope}]`, `circle{...}`, `created_at`, `updated_at`).

**GET /api/collab** — item: `id, title, status, required_member, accepted_member_count, available_slots, skills_needed[], owner{id,username,photo_profile_url}`. TIDAK ada `description`, `media_urls`, `created_at`, `circle`.

**GET /api/collab/:id** — item: `id, title, description, required_member, accepted_member_count, available_slots, status, media_urls[], media_public_ids[], skills_needed[], circle{id,collaboration_id,title,photo_profile_url,timestamp}, owner{...}, created_at, updated_at`.

**GET /api/profiles/:userId/collab** — hanya bekerja jika `:userId` = user login (404 `Bukan Punya elu` selain itu). Item menggandakan nama: `id/_id`, `userId`+`user_id` (object {id,_id,username,photo_profile_url}), `required_member`+`requiredMember`, `media_urls`+`mediaUrls`, `skills_needed`+`skillsNeeded`, `created_at/createdAt`, dll. Tidak ada `accepted_member_count`/`available_slots`.

**PUT /api/collab/:id** — payload parsial (detail §5).

**DELETE /api/collab/:id** — 200 + objek collab (format detail) sebelum dihapus; 403 bukan owner.

## 5. Audit `updateCollab()` (CollabController.php:250–459)

- Method HTTP: **PUT** (terdaftar di api.php:92).
- Body dari `$GLOBALS['body']` (fallback re-read php://input).
- Field terproses: `title`, `description`, `status` (string bebas, tanpa validasi enum!), `required_member` (numeric ≥1), `media_urls`+`media_public_ids`, `skills_needed`.
- Perilaku (terverifikasi live):
  - Empty body / semua field identik → **400 `Tidak ada data yang diperbarui`** (karena `$updateData` hanya diisi field non-empty dari body).
  - **Media-only atau skills-only TANPA field utama → media/skills tetap diubah (commit sendiri), lalu 400.** Data sudah berubah tapi response gagal → tidak atomik.
  - Skills invalid (ada UUID tak dikenal) → 400, tapi **media yang dikirim di request yang sama sudah di-commit sebelumnya**.
  - Array kosong `[]` untuk media/skills diabaikan total — **impossible to clear** (condition `count > 0`).
  - `status` diterima apa saja (mis. `"foo"`); hanya ditolak jika ENUM DB menolak saat execute → 500.
  - Sinkronisasi circle.title hanya jika `title` dikirim DAN beda dari sebelumnya (updateCollab:416–424).
  - Response sukses = `CollabModel::findById($id)` (format detail; punya `user_id`, TANPA `owner`).
  - `error_log('UPDATE DATA: ...')` hardcoded di line 436 (leak payload ke log server).

## 6. Audit CollabModel (ringkas)

- `create()`: transaksi tunggal (collab + media + skills + circle), validasi FK skills, rollback on error. Respons create = `findById` (detail) — shape berbeda dengan list/detail endpoint.
- `getAll()`: batch query (no N+1). `accepted_member_count` = `COUNT(*) FROM user_collaboration` tanpa filter status — benar secara definisi (hanya row = anggota diterima), tapi tabel ini kosong di sistem karena tidak ada endpoint Application.
- `getByUserId()`: gandakan field camel/snake; skills di sini punya `_id` tambahan, di list/detail tidak — inkonsistensi shape antar endpoint.
- `delete()`: transaksi + rely ON DELETE CASCADE (terverifikasi: FK cascade ada di semua child table).

## 7. Integrasi Application & User Collaboration

**BELUM TERVERIFIKASI / TIDAK ADA**: tidak ada ApplicationController/Model/route. `collaboration_applications` (0 row) dan `user_collaboration` (0 row) tidak pernah ditulis oleh kode PHP. Alur apply → accept → capacity check → auto-close **belum diimplementasikan sama sekali**.

## 8. Analisis Status & Kapasitas

- `available_slots = max(0, required_member - accepted_member_count)` dihitung di query, bukan di DB — konsisten di list/detail.
- **Owner tidak di-count sebagai anggota** (tidak ada auto-insert owner ke `user_collaboration` saat create).
- Kapasitas tidak bisa terlampaui karena tidak ada jalur acceptance sama sekali.
- Status TIDAK berubah otomatis saat kapasitas penuh (tidak ada mekanisme).
- Perubahan `required_member` tidak memvalidasi terhadap `accepted_member_count` (bisa menurunkan di bawah jumlah anggota jika fitur accept ada).
- 2 application hampir bersamaan → race condition BELUM TERVERIFIKASI (kode acceptance tidak ada).
- Status bisa `open`/`closed` secara manual via PUT (terverifikasi).

## 9. Audit Database (skema live terverifikasi)

- `collaborations`: PK `id` UUID default, `status ENUM('open','closed')` DEFAULT 'open', `updated_at ON UPDATE CURRENT_TIMESTAMP` (otomatis), FK user CASCADE. Kolom `communication_url` masih ada (NULL, deprecated).
- `collab_media`, `collab_skills` (unique `(collab_id,skill_id)`), `collaboration_applications` (enum `pending/accepted/rejected`), `user_collaboration` (unique `(user_id,collaboration_id)`), `circle` (unique `collaboration_id`).
- Divergensi: migration 20261018 menulis `communication_url VARCHAR(255) NOT NULL` tapi live DB = NULL DEFAULT NULL (migration 20261019 sudah di-apply). `zone`/`zone_messages` ada di schema.test.sql tapi **tidak ada di live DB**.
- Tanpa modul Application, tidak ada transaksi multi-tabel di jalur acceptance (belum ada).

## 10. Bug & Ketidakkonsistenan (dengan bukti)

| # | Lokasi | Bukti | Dampak | Prioritas |
|---|---|---|---|---|
| B1 | `updateCollab` 331–357: transaksi media & skills terpisah dari update utama | Terverifikasi: media di-commit, lalu skills invalid → 400, data berubah setengah | FE dapat response gagal tapi state DB berubah; tidak bisa rollback | HIGH |
| B2 | `updateCollab`: media-only/skills-only tanpa field utama → 400 padahal sudah disimpan | Terverifikasi live (response 400, DB terisi) | Response tidak merepresentasikan state; FE tak bisa tahu media ter-update | HIGH |
| B3 | `updateCollab`: `status` tanpa validasi enum | Kode line 279,316–318; DB ENUM akan tolak → 500 | 500 tak terduga | MED |
| B4 | `updateCollab` 328: `count($mediaUrls) > 0` → array kosong tak bisa clear media/skills | Kondisi if line 328 & 361 | FE tidak bisa menghapus seluruh media/skills | MED |
| B5 | `deleteCollab`: DELETE hanya 1 tabel dalam transaksi, reliance pada CASCADE | `CollabModel::delete` 653–670 | Aman (terverifikasi FK cascade), tapi zone_messages tak ada di live DB | LOW |
| B6 | Modul Application missing entirely (section 7) | Tidak ada route/model | Seluruh alur apply/accept/capacity tidak berfungsi | HIGH (gap fitur) |
| B7 | `yourCollabPost`: 404 `Bukan Punya elu` untuk user lain; endpoint "your collab" hanya bisa sendiri | Terverifikasi live | Tidak bisa lihat collab milik orang lain via endpoint ini (by design?) | INFO |
| B8 | `error_log` payload update hardcoded (line 436) | Kode | Bocor data user ke log; noise | LOW |
| B9 | Response create vs detail vs list beda shape (create=detail tanpa owner; detail ada owner; list tanpa desc/media) | Terverifikasi | FE harus handle 3 shape berbeda | MED |
| B10 | `Router` `matchRoute` line 208–217: `preg_replace_callback` dipanggil tapi hasilnya di-override `buildRegex` (dead code + bug: `preg_quote` sebelum callback membuat `:` hilang sehingga callback tak pernah match) | Kode | Tidak berdampak karena `buildRegex` menggantikan; tapi dead code confusing | LOW |

## 11. Rekomendasi Kontrak API untuk FE

1. Gunakan `title`, `description`, `required_member`, `skills_needed[]` (UUID string dari tabel skills), `media_urls[]`, `media_public_ids[]` — kirim dua-duanya dengan count sama, atau tidak sama sekali.
2. Field untuk form: title, description, required_member, skills_needed, media. Field hanya tampilan: `status`, `accepted_member_count`, `available_slots`, `created_at`, `updated_at`, `owner`, `circle`.
3. PUT: kirim hanya field yang berubah; jangan berharap bisa clear array dengan `[]`; jangan kirim `status` di luar `open`/`closed`.
4. GET `/api/collab` (list) tidak memuat description/media → gunakan detail endpoint untuk form edit.
5. GET `/api/profiles/:userId/collab` hanya untuk diri sendiri.
6. Jangan andalkan `communication_url` (deprecated, tidak diresponse).
7. Capacity/apply flow belum ada → FE jangan render tombol "Apply" sampai modul Application diimplementasikan.

## 12. Prioritas Perbaikan Backend

1. **HIGH** — Gabungkan media+skills+collaborations update ke SATU transaksi (fix B1/B2).
2. **HIGH** — Implementasikan modul Application (POST apply, PUT accept/reject, capacity check, auto-close saat penuh, owner masuk `user_collaboration` saat create) — gap B6.
3. **MED** — Validasi `status` in `['open','closed']`; dukung clear array (B3/B4).
4. **MED** — Seragamkan shape response create/detail/list (B9).
5. **LOW** — Hapus `error_log` payload (B8), bersihkan dead code router (B10).

## 13. Checklist Pengujian Bruno/Postman

- [ ] POST /api/collab: valid → 200 + detail; tanpa skills → 400; skill UUID palsu → 400; media count tak sama → 400; tanpa token → 403.
- [ ] GET /api/collab: 200 array; cek shape (tanpa description/media).
- [ ] GET /api/collab/:id: 200 detail; ID tak ada → 404.
- [ ] GET /api/profiles/:userId/collab: userId = owner → 200; user lain → 404.
- [ ] PUT /api/collab/:id: field owner hanya → 200 + data terbaru; non-owner → 403; body kosong → 400; media-only → verifikasi response vs DB (B2); skills invalid + media valid → verifikasi (B1); status="foo" → verifikasi (B3); `skills_needed: []` → verifikasi (B4).
- [ ] DELETE /api/collab/:id: owner → 200; non-owner → 403; verify child rows terhapus (CASCADE).
- [ ] Regression: after update, GET detail mencerminkan perubahan title+circle.title.

## 14. File yang Perlu Diperiksa Lebih Lanjut

- `tests/unit_test.php` (menyebut route `/api/collab/applications/:applicationId/accept` → indikasi desain modul Application yang belum diimplementasi).
- Baseline Express `backend-astel/src` untuk kontrak Application.
- `.env` (DB live terverifikasi: 3 collab, 0 applications, 0 user_collaboration, 3 circle).

## Kesimpulan

- **Berjalan sesuai implementasi**: CRUD collab + circle auto-create, auth JWT, ownership check, list/detail, update parsial field utama, delete cascade, UUID, transaksi create atomik, `available_slots` dihitung benar terhadap `user_collaboration` (yang kini selalu kosong).
- **Belum berjalan sesuai aturan bisnis**: seluruh alur Application/acceptance/kapasitas/anggota — modul-nya belum ada di PHP.
- **Berpotensi merusak integrasi FE**: B1/B2 (response gagal tapi DB berubah), shape response beda antar endpoint (B9), array tak bisa dikosongkan (B4).
- **Yang harus diketahui FE**: list ≠ detail ≠ create response; `yourCollabPost` hanya untuk diri sendiri; `status` hanya `open`/`closed`; tidak ada endpoint apply.
- **Perbaiki lebih dulu**: transaksi atomik update (B1/B2) + modul Application.
- **Belum dapat dipastikan**: perilaku concurrency acceptance (tidak ada kodenya), apakah `zone`/`zone_messages` akan dibuat di live DB, desain owner-as-member (belum ada kebijakan).
