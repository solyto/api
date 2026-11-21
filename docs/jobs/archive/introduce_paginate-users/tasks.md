# Tasks: paginate-users

id: introduce
analyst:
date:

<!-- Produced by @analyst from brief.md. -->

## Task breakdown

TASK-1: Paginate GET /api/v1/users (admin user list): validated page/per_page,
pagination meta in the envelope, stable ordering, OpenAPI, tests.
Released frontends send no per_page and keep the old page size of 1000 (STANDARDS 13.1).
files: app/Api/Users/Controllers/UserController.php, app/Api/Users/Requests/ListUsersRequest.php,
app/Api/Users/Services/UserService.php, app/Api/ApiResponse.php, app/Api/Users/Tests/UserApiTest.php
depends: none
risk: low (additive contract change, admin-only endpoint)

TASK-2: Keep the admin page's existing features working with paging (requested by the app side in room paginate-users):
server-side `search` on GET /api/v1/users, and GET /api/v1/users/growth for the signup chart.
files: app/Api/Users/Controllers/UserController.php, app/Api/Users/Services/UserService.php,
app/Api/Users/Requests/{ListUsersRequest,UserGrowthRequest}.php, app/Api/Users/Enums/UserGrowthPeriod.php,
routes/api.php, app/Api/Users/Tests/UserApiTest.php
depends: TASK-1
risk: low (new endpoint plus an optional parameter, both admin-only)
