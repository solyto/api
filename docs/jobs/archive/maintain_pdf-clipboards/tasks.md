# Tasks: pdf-clipboards

id: maintain
analyst: ebenezer (room pdf-clipboards, decisions by human)
date: 2026-11-16

## Task breakdown

TASK-1: Clipboard file entries (pdf, txt, md, csv, doc, docx, ppt, pptx, xls, xlsx)
  - migration: type enum + 'file', columns file_name, file_size, mime_type
  - POST /clipboard/file: extension + finfo whitelist (422), 10 MB (413), 10 uploads per user per day (429)
  - GET /clipboard/{id}/file: owner-only attachment download, nosniff, CSP
  - stored on private_user_data with a random name; DELETE + daily cleanup remove it
  - GET /clipboard lists file entries only with ?include_files=1 (STANDARDS 13.3)
  files: app/Api/Clipboard/*, config/clipboard.php, routes/api.php, AppServiceProvider, migration
  depends: none
  risk: medium — file upload surface

TASK-2: Clipboard images behind auth
  - store images on private_user_data, move existing ones (data migration)
  files: app/Api/Clipboard/*, migration
  depends: TASK-1, owner decision on old frontends
  risk: medium — released frontends render images from public storage
