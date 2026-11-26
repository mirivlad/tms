"""Exercise the MCP HTTP boundary against an isolated or CI TMS installation."""
import json
import os
import urllib.error
import urllib.request

base = os.environ["APP_URL"].rstrip("/") + "/mcp"
write = os.environ["MCP_WRITE_TOKEN"]
read = os.environ["MCP_READ_TOKEN"]
foreign = os.environ["MCP_FOREIGN_TOKEN"]


def rpc(method, params=None, token=write, expected=200, origin=None, notification=False):
    payload = {"jsonrpc": "2.0", "method": method, "params": params or {}}
    if not notification:
        payload["id"] = 1
    headers = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if origin:
        headers["Origin"] = origin
    request = urllib.request.Request(base, json.dumps(payload).encode(), headers)
    try:
        response = urllib.request.urlopen(request, timeout=15)
    except urllib.error.HTTPError as exc:
        response = exc
    with response:
        body = response.read()
        assert response.status == expected, (method, response.status, body[:500])
        return json.loads(body) if body else {}


def call(name, args, token=write, error=False):
    result = rpc("tools/call", {"name": name, "arguments": args}, token)["result"]
    assert bool(result.get("isError", False)) == error, (name, result)
    return None if error else json.loads(result["content"][0]["text"])


if os.environ.get("MCP_EXPECT_REVOKED") == "1":
    rpc("tools/list", token=read, expected=401)
    print("MCP token revocation passed")
    raise SystemExit(0)

rpc("tools/list", token="", expected=401)
rpc("tools/list", origin="https://untrusted.invalid", expected=403)
assert rpc("initialize", {"protocolVersion": "2025-11-25"})["result"]["protocolVersion"] == "2025-11-25"
rpc("notifications/initialized", expected=202, notification=True)
assert len(rpc("tools/list", token=read)["result"]["tools"]) == 9
assert len(rpc("tools/list")["result"]["tools"]) == 18
call("list_projects", {})
call("list_statuses", {})
args = {"title": "MCP smoke test", "description": "<script>alert(1)</script>", "priority": 2,
        "deadline": "2030-01-02T12:00:00+08:00", "checklist": ["One", "Two"], "request_key": "smoke-create"}
call("create_task", args, read, error=True)
call("create_task", dict(args, priority=4), error=True)
# The second, invalid checklist item must roll back the first item and task.
call("create_task", dict(args, title="MCP rollback test", checklist=["Valid", " "], request_key="smoke-rollback"), error=True)
assert call("list_tasks", {"query": "MCP rollback test"})["total"] == 0
created = call("create_task", args)
task_id = created["task_id"]
assert len(created["checklist"]) == 2
assert "<script>" not in created["task"]["description"]
assert call("create_task", args)["replayed"]
call("create_task", dict(args, title="Different title"), error=True)
assert call("list_tasks", {"query": "MCP smoke test"})["total"] == 1
assert call("list_tasks", {"query": "MCP smoke test"}, foreign)["total"] == 0
call("get_task", {"task_id": task_id}, foreign, error=True)
call("update_task", {"task_id": task_id, "title": "Forbidden", "request_key": "foreign"}, foreign, error=True)
changed = call("update_task", {"task_id": task_id, "priority": 3, "request_key": "smoke-update"})
assert changed["task"]["priority"] == 3 and changed["task"]["deadline"] == created["task"]["deadline"]
call("add_checklist_item", {"task_id": task_id, "text": "Three", "request_key": "smoke-add"})
done = call("set_checklist_completed", {"task_id": task_id, "item_id": created["checklist"][0]["id"], "completed": True, "request_key": "smoke-check"})
assert len(done["checklist"]) == 3 and done["checklist"][0]["isCompleted"]
completed = call("complete_task", {"task_id": task_id, "request_key": "smoke-complete"})
statuses = call("list_statuses", {})["statuses"]
assert any(s["id"] == completed["task"]["statusId"] and s["isCompletion"] for s in statuses)
assert call("get_task", {"task_id": task_id}, read)["task"]["id"] == task_id
print("MCP smoke tests passed")


if os.environ.get("MCP_TEST_USER"):
    import http.cookiejar
    import re
    import urllib.parse

    site = os.environ["APP_URL"].rstrip("/")
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def page(path, fields=None, expected=200):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            response = opener.open(site + path, data, timeout=15)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            assert response.status == expected, (path, response.status)
            return response.read().decode(), response.headers

    def csrf(html):
        return re.search(r'name="_csrf" value="([^"]+)"', html).group(1)

    login, _ = page("/login")
    page("/login", {"_csrf": csrf(login), "username": os.environ["MCP_TEST_USER"], "password": os.environ["MCP_TEST_PASSWORD"]})
    settings, headers = page("/settings/mcp")
    assert "no-store" in headers["Cache-Control"]
    page("/settings/mcp", {"label": "browser token", "scope": "read", "days": "1", "password": "wrong", "_csrf": csrf(settings)}, 422)
    page("/settings/mcp", {"label": "browser token", "scope": "read", "days": "1", "password": os.environ["MCP_TEST_PASSWORD"]}, 403)
    created, headers = page("/settings/mcp", {"label": "browser token", "scope": "read", "days": "1", "password": os.environ["MCP_TEST_PASSWORD"], "_csrf": csrf(settings)})
    secret = re.search(r'tms_mcp_[0-9a-f]{64}', created).group(0)
    assert len(rpc("tools/list", token=secret)["result"]["tools"]) == 9
    refreshed, _ = page("/settings/mcp")
    assert secret not in refreshed
    print("MCP browser token issuance, CSRF and one-time disclosure passed")

    permanent_form = {"label": "permanent-browser-token", "scope": "read", "days": "0", "password": os.environ["MCP_TEST_PASSWORD"], "_csrf": csrf(refreshed)}
    created, _ = page("/settings/mcp", permanent_form)
    permanent = re.search(r'tms_mcp_[0-9a-f]{64}', created).group(0)
    assert "No expiration" in created or "Бессрочно" in created
    assert len(rpc("tools/list", token=permanent)["result"]["tools"]) == 9
    row = next(row for row in re.findall(r"<tr>.*?</tr>", created, re.S) if "permanent-browser-token" in row)
    revoke_path = re.search(r'action="(/settings/mcp/[0-9]+/revoke)"', row).group(1)
    page(revoke_path, {"_csrf": csrf(created)})
    rpc("tools/list", token=permanent, expected=401)
    invalid = dict(permanent_form, days="366")
    page("/settings/mcp", invalid, 422)
    print("Permanent token issuance, read-only access, revocation and validity bounds passed")

    # Issue the new opt-in scope through the browser on the disposable test DB.
    refreshed, _ = page("/settings/mcp")
    deletion_form = dict(permanent_form, scope="delete", label="deletion-test", _csrf=csrf(refreshed))
    created, _ = page("/settings/mcp", deletion_form)
    deletion_token = re.search(r'tms_mcp_[0-9a-f]{64}', created).group(0)
    tools = {t["name"]: t for t in rpc("tools/list", token=deletion_token)["result"]["tools"]}
    assert len(tools) == 21
    assert tools["delete_task"]["annotations"]["destructiveHint"]
    assert tools["delete_tasks"]["annotations"]["destructiveHint"]
    assert not tools["delete_tasks"]["annotations"]["readOnlyHint"]
    assert len(rpc("tools/list")["result"]["tools"]) == 18
    print("MCP opt-in scope issuance and tool discovery passed")

    # Every fixture is created by this smoke test; the production API is not used.
    def fixture(label, token=write):
        return call("create_task", {"title": "MCP disposable fixture " + label,
                                   "request_key": "fixture-" + label}, token)["task_id"]

    a, b, c = (fixture(label) for label in ("a", "b", "c"))
    outsider = fixture("foreign", foreign)
    # Attach a small file using the normal browser upload workflow.
    from pathlib import Path
    attachment_root = os.environ.get("ATTACHMENT_STORAGE_PATH")
    def stored_files():
        return set(Path(attachment_root).glob("*/*")) if attachment_root else set()
    before = stored_files()
    edit_page, _ = page(f"/tasks/{a}/edit")
    boundary = "tms-smoke-fixture-attachment"
    payload = (
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n{csrf(edit_page)}\r\n"
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"attachments[]\"; filename=\"mcp-fixture.txt\"\r\n"
        f"Content-Type: text/plain\r\n\r\nMCP disposable attachment\r\n--{boundary}--\r\n"
    ).encode()
    upload = urllib.request.Request(site + f"/tasks/{a}/attachments", payload,
                                    {"Content-Type": f"multipart/form-data; boundary={boundary}"})
    with opener.open(upload, timeout=15) as response:
        assert response.status == 200
    preview, _ = page(f"/api/tasks/{a}")
    attachment = re.search(rf'/tasks/{a}/attachments/[0-9]+', preview).group(0)
    assert page(attachment)[0].strip() == "MCP disposable attachment"
    new_files = stored_files() - before
    if attachment_root:
        assert len(new_files) == 1
    single = {"task_id": c, "confirmed": True, "request_key": "fixture-single"}
    for restricted in (read, write, foreign):
        call("delete_task", single, restricted, error=True)
    call("delete_task", dict(single, confirmed=False), deletion_token, error=True)
    assert call("get_task", {"task_id": c})["task"]["id"] == c

    batch = {"task_ids": [a, b], "confirmed": True, "request_key": "fixture-batch"}
    for invalid in ([], [a, a], [a, outsider], [a, 2147483647]):
        call("delete_tasks", dict(batch, task_ids=invalid), deletion_token, error=True)
        assert call("get_task", {"task_id": a})["task"]["id"] == a
        assert call("get_task", {"task_id": b})["task"]["id"] == b

    result = call("delete_task", single, deletion_token)
    assert result["deleted_ids"] == [c] and not result["replayed"]
    assert result["attachment_cleanup_pending"] == 0
    assert call("delete_task", single, deletion_token)["replayed"]
    call("get_task", {"task_id": c}, error=True)
    assert page(attachment)[0].strip() == "MCP disposable attachment"
    result = call("delete_tasks", batch, deletion_token)
    assert result["deleted_count"] == 2 and result["deleted_ids"] == sorted([a, b])
    assert not result["replayed"] and result["attachment_cleanup_pending"] == 0
    assert call("delete_tasks", batch, deletion_token)["replayed"]
    page(attachment, expected=404)
    if attachment_root:
        assert not any(path.exists() for path in new_files)
    call("delete_tasks", dict(batch, task_ids=[outsider]), deletion_token, error=True)
    for fixture_id in (a, b):
        call("get_task", {"task_id": fixture_id}, error=True)
    assert call("get_task", {"task_id": outsider}, foreign)["task"]["id"] == outsider
    assert call("get_task", {"task_id": task_id})["task"]["id"] == task_id
    print("MCP disposal of fixtures: authorization, atomicity, confirmation and replay passed")

# Project lifecycle and idempotent project receipts.
project_args = {"name": "MCP fixture project", "description": "Project fixture", "request_key": "project-create"}
call("create_project", project_args, read, error=True)
p = call("create_project", project_args)
pid = p["project_id"]
assert p["project"]["name"] == project_args["name"] and p["can_manage"]
assert p["statuses"] and not p["replayed"]
assert call("create_project", project_args)["project_id"] == pid
assert call("create_project", project_args)["replayed"]
call("create_project", dict(project_args, name="Different name"), error=True)
call("get_project", {"project_id": pid}, foreign, error=True)
call("update_project", {"project_id": pid, "name": "Forbidden", "request_key": "project-foreign"}, foreign, error=True)
changed_project = call("update_project", {"project_id": pid, "lifecycle_status": "archived", "request_key": "project-archive"})
assert changed_project["project"]["lifecycleStatus"] == "archived"
assert changed_project["project"]["description"] == project_args["description"]
call("update_project", {"project_id": pid, "lifecycle_status": "invalid", "request_key": "project-invalid"}, error=True)
assert call("get_project", {"project_id": pid}, read)["project"]["lifecycleStatus"] == "archived"
call("update_project", {"project_id": pid, "lifecycle_status": "active", "request_key": "project-active"})
assert call("list_project_assignees", {"project_id": pid})["assignees"] == []
call("list_teams", {})
call("list_task_types", {})
call("list_customers", {})

# Moving a task must switch status scope, not discard the task or its checklist.
args = {"title": "MCP fixture move", "checklist": ["First", "Second"], "request_key": "project-task"}
moving = call("create_task", args)
tid = moving["task_id"]
move = {"task_id": tid, "project_id": pid, "request_key": "move-to-project"}
moved = call("update_task", move)
assert moved["task"]["projectId"] == pid
assert moved["task"]["statusId"] in [s["id"] for s in p["statuses"]]
assert len(moved["checklist"]) == 2
assert call("update_task", move)["replayed"]
call("update_task", {"task_id": tid, "assignee_user_id": 1, "request_key": "personal-assignee"}, error=True)
call("update_task", {"task_id": tid, "project_id": 2147483647, "request_key": "invalid-move"}, error=True)
assert call("get_task", {"task_id": tid})["task"]["projectId"] == pid
back = call("update_task", {"task_id": tid, "project_id": None, "request_key": "move-to-personal"})
assert back["task"]["projectId"] is None
first, second = (i["id"] for i in back["checklist"])
renamed = call("update_checklist_item", {"task_id": tid, "item_id": first, "text": "Renamed", "request_key": "item-rename"})
assert renamed["checklist"][0]["text"] == "Renamed"
shift = {"task_id": tid, "item_id": second, "direction": "up", "request_key": "item-move"}
assert call("move_checklist_item", shift)["checklist"][0]["id"] == second
assert call("move_checklist_item", shift)["replayed"]
assert call("get_task", {"task_id": tid})["checklist"][0]["id"] == second
if os.environ.get("MCP_TEST_USER"):
    remove = {"task_id": tid, "item_id": second, "confirmed": True, "request_key": "item-remove"}
    call("delete_checklist_item", remove, error=True)
    call("delete_checklist_item", dict(remove, confirmed=False), deletion_token, error=True)
    remaining = call("delete_checklist_item", remove, deletion_token)
    assert [i["id"] for i in remaining["checklist"]] == [first]
    assert call("delete_checklist_item", remove, deletion_token)["replayed"]
print("MCP projects, task moves and checklist changes passed")

if os.environ.get("MCP_TEST_USER"):
    metadata, _ = page("/metadata")
    page("/metadata/types", {"_csrf": csrf(metadata), "name": "MCP fixture type", "description": ""})
    metadata, _ = page("/metadata")
    page("/metadata/customers", {"_csrf": csrf(metadata), "name": "MCP fixture customer"})
    typ = next(t["id"] for t in call("list_task_types", {})["task_types"] if t["name"] == "MCP fixture type")
    customer = next(t["id"] for t in call("list_customers", {})["customers"] if t["name"] == "MCP fixture customer")
    call("update_task", {"task_id": tid, "type_id": typ, "customer_id": customer, "request_key": "metadata-set"})
    meta = call("get_task", {"task_id": tid})["task"]
    assert meta["typeId"] == typ and meta["customerId"] == customer
    assert all(t["id"] != typ for t in call("list_task_types", {}, foreign)["task_types"])
    assert all(t["id"] != customer for t in call("list_customers", {}, foreign)["customers"])
    cleared = call("update_task", {"task_id": tid, "type_id": None, "customer_id": None, "request_key": "metadata-clear"})["task"]
    assert cleared["typeId"] is None and cleared["customerId"] is None
    print("MCP personal metadata and isolation passed")

if os.environ.get("MCP_TEST_USER") and os.environ.get("MCP_FOREIGN_USER"):
    teams_page, _ = page("/teams")
    page("/teams", {"_csrf": csrf(teams_page), "name": "MCP fixture team", "description": "Disposable team"})
    team = next(t for t in call("list_teams", {})["teams"] if t["name"] == "MCP fixture team")
    team_id = team["id"]
    assert team["currentUserRole"] == "lead"
    assert all(t["id"] != team_id for t in call("list_teams", {}, foreign)["teams"])
    team_args = {"name": "MCP team project", "team_id": team_id, "request_key": "team-project"}
    call("create_project", team_args, foreign, error=True)
    team_project = call("create_project", team_args)
    shared = team_project["project_id"]
    call("get_project", {"project_id": shared}, foreign, error=True)
    assert call("create_project", team_args)["project_id"] == shared
    members, _ = page(f"/teams/{team_id}/members")
    page(f"/teams/{team_id}/invitations", {"_csrf": csrf(members), "identifier": os.environ["MCP_FOREIGN_USER"]})
    lead_opener = opener
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    login, _ = page("/login")
    page("/login", {"_csrf": csrf(login), "username": os.environ["MCP_FOREIGN_USER"], "password": os.environ["MCP_FOREIGN_PASSWORD"]})
    invitations, _ = page("/invitations")
    accept = re.search(r'action="(/invitations/[0-9]+/accept)"', invitations).group(1)
    page(accept, {"_csrf": csrf(invitations)})
    opener = lead_opener
    assert not call("get_project", {"project_id": shared}, foreign)["can_manage"]
    call("create_project", dict(team_args, request_key="member-project"), foreign, error=True)
    call("update_project", {"project_id": shared, "name": "Forbidden", "request_key": "member-edit"}, foreign, error=True)
    assignees = call("list_project_assignees", {"project_id": shared}, foreign)["assignees"]
    member_id = next(m["user_id"] for m in assignees if m["username"] == os.environ["MCP_FOREIGN_USER"])
    assert all("email" not in m for m in assignees)
    shared_task = call("create_task", {"title": "MCP collaborative fixture", "project_id": shared,
                                      "assignee_user_id": member_id, "request_key": "collaborative-task"}, foreign)
    shared_id = shared_task["task_id"]
    assert call("get_task", {"task_id": shared_id})["task"]["assigneeUserId"] == member_id
    call("update_task", {"task_id": shared_id, "project_id": pid, "assignee_user_id": None, "request_key": "foreign-move"}, error=True)
    call("update_task", {"task_id": shared_id, "assignee_user_id": 2147483647, "request_key": "bad-assignee"}, error=True)
    call("update_task", {"task_id": shared_id, "type_id": typ, "request_key": "team-type"}, error=True)
    assert call("update_task", {"task_id": shared_id, "assignee_user_id": None, "request_key": "unassign"})["task"]["assigneeUserId"] is None
    print("MCP team project, member/lead rights and assignee checks passed")

if os.environ.get("MCP_TEST_USER"):
    # A project with required fields cannot receive an incomplete MCP task.
    field_page, _ = page(f"/projects/{pid}/fields")
    page(f"/projects/{pid}/fields", {"_csrf": csrf(field_page), "name": "MCP required fixture",
                                     "field_type": "text", "is_required": "1"})
    call("create_task", {"title": "MCP incomplete fixture", "project_id": pid, "request_key": "missing-required-field"}, error=True)
    call("update_task", {"task_id": tid, "project_id": pid, "request_key": "move-required-field"}, error=True)
    assert call("get_task", {"task_id": tid})["task"]["projectId"] is None
    print("MCP required custom-field guard passed")
