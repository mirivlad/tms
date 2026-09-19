#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
ADMIN_COOKIES=/tmp/tms-cookies
MEMBER_COOKIES=/tmp/tms-team-member-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams" > /tmp/teams-admin.html
admin_csrf=$(csrf_from /tmp/teams-admin.html)
test -n "$admin_csrf"
grep -q 'href="/teams"' /tmp/teams-admin.html
grep -q 'href="/invitations"' /tmp/teams-admin.html

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_csrf"   --data-urlencode 'name=CI Team Core'   --data-urlencode 'description=Team core smoke'   "$BASE_URL/teams")
test "$code" = "302"

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
team_id=$(db "SELECT id FROM teams WHERE name='CI Team Core' AND created_by=$admin_id LIMIT 1")
test -n "$team_id"
test "$(db "SELECT role FROM team_members WHERE team_id=$team_id AND user_id=$admin_id")" = "lead"

member_hash=$(docker compose exec -T app php -r 'echo password_hash("team-member-password-12345", PASSWORD_DEFAULT);')
member_hash_sql=$(printf '%s' "$member_hash" | sed "s/'/''/g")
db "INSERT INTO users (
      username, email, password_hash, role, is_active, email_verified_at, approved_at
    ) VALUES (
      'teammember', 'teammember@example.invalid', '$member_hash_sql', 'user', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )"
member_id=$(db "SELECT id FROM users WHERE username='teammember' LIMIT 1")
test -n "$member_id"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_id" > /tmp/team-detail-admin.html
team_csrf=$(csrf_from /tmp/team-detail-admin.html)
test -n "$team_csrf"

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$team_csrf"   --data-urlencode 'identifier=teammember'   "$BASE_URL/teams/$team_id/invitations")
test "$code" = "302"

invitation_id=$(db "SELECT id FROM team_invitations WHERE team_id=$team_id AND invited_user_id=$member_id AND status='pending' LIMIT 1")
test -n "$invitation_id"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/dashboard" > /tmp/team-dashboard-admin.html
grep -q 'class="nav-invitations has-unread"' /tmp/team-dashboard-admin.html || true

# Log in as the invited user in an independent browser session.
curl --fail --silent --cookie-jar "$MEMBER_COOKIES" "$BASE_URL/login" > /tmp/team-member-login.html
login_csrf=$(csrf_from /tmp/team-member-login.html)
test -n "$login_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES" --cookie-jar "$MEMBER_COOKIES"   --data-urlencode "_csrf=$login_csrf"   --data-urlencode 'username=teammember'   --data-urlencode 'password=team-member-password-12345'   "$BASE_URL/login")
test "$code" = "302"

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/invitations" > /tmp/team-member-invitations.html
member_csrf=$(csrf_from /tmp/team-member-invitations.html)
test -n "$member_csrf"
grep -q 'CI Team Core' /tmp/team-member-invitations.html
grep -q 'class="nav-invitations has-unread"' /tmp/team-member-invitations.html
grep -q '>1<' /tmp/team-member-invitations.html

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   "$BASE_URL/invitations/$invitation_id/accept")
test "$code" = "302"
test "$(db "SELECT status FROM team_invitations WHERE id=$invitation_id")" = "accepted"
test "$(db "SELECT role FROM team_members WHERE team_id=$team_id AND user_id=$member_id")" = "member"

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/teams/$team_id" > /tmp/team-member-detail.html
member_team_csrf=$(csrf_from /tmp/team-member-detail.html)
test -n "$member_team_csrf"
grep -q 'CI Team Core' /tmp/team-member-detail.html
if grep -q "action=\"/teams/$team_id/invitations\"" /tmp/team-member-detail.html; then
  echo 'Ordinary member received Lead invitation controls.' >&2
  exit 1
fi

# Member cannot mutate team settings.
code=$(curl --silent -o /tmp/team-member-forbidden.txt -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_team_csrf"   --data-urlencode 'name=Stolen Team'   --data-urlencode 'description=forbidden'   "$BASE_URL/teams/$team_id")
test "$code" = "404"
test "$(db "SELECT name FROM teams WHERE id=$team_id")" = "CI Team Core"

# Lead can promote; then the new Lead can change another member's role.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$team_csrf"   --data-urlencode 'role=lead'   "$BASE_URL/teams/$team_id/members/$member_id/role")
test "$code" = "302"
test "$(db "SELECT role FROM team_members WHERE team_id=$team_id AND user_id=$member_id")" = "lead"

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/teams/$team_id" > /tmp/team-member-lead.html
member_team_csrf=$(csrf_from /tmp/team-member-lead.html)
grep -q "action=\"/teams/$team_id/invitations\"" /tmp/team-member-lead.html

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_team_csrf"   --data-urlencode 'role=member'   "$BASE_URL/teams/$team_id/members/$admin_id/role")
test "$code" = "302"
test "$(db "SELECT role FROM team_members WHERE team_id=$team_id AND user_id=$admin_id")" = "member"

# The last remaining Lead cannot leave.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_team_csrf"   "$BASE_URL/teams/$team_id/leave")
test "$code" = "302"
test "$(db "SELECT role FROM team_members WHERE team_id=$team_id AND user_id=$member_id")" = "lead"

# Deployment administration must not bypass the team last-Lead invariant.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/admin/users/$member_id/edit" > /tmp/team-admin-user-edit.html
admin_user_csrf=$(csrf_from /tmp/team-admin-user-edit.html)
test -n "$admin_user_csrf"

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_user_csrf"   --data-urlencode 'username=teammember'   --data-urlencode 'email=teammember@example.invalid'   --data-urlencode 'role=user'   "$BASE_URL/admin/users/$member_id/edit")
test "$code" = "302"
test "$(db "SELECT is_active FROM users WHERE id=$member_id")" = "1"
test "$(db "SELECT role FROM team_members WHERE team_id=$team_id AND user_id=$member_id")" = "lead"

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_user_csrf"   "$BASE_URL/admin/users/$member_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM users WHERE id=$member_id")" = "1"
test "$(db "SELECT role FROM team_members WHERE team_id=$team_id AND user_id=$member_id")" = "lead"

# Restore admin as Lead, leave as the second Lead, then delete the team.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_team_csrf"   --data-urlencode 'role=lead'   "$BASE_URL/teams/$team_id/members/$admin_id/role")
test "$code" = "302"

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_team_csrf"   "$BASE_URL/teams/$team_id/leave")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM team_members WHERE team_id=$team_id AND user_id=$member_id")" = "0"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_id" > /tmp/team-admin-final.html
team_csrf=$(csrf_from /tmp/team-admin-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$team_csrf"   "$BASE_URL/teams/$team_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM teams WHERE id=$team_id")" = "0"
test "$(db "SELECT COUNT(*) FROM team_members WHERE team_id=$team_id")" = "0"
test "$(db "SELECT COUNT(*) FROM team_invitations WHERE team_id=$team_id")" = "0"
