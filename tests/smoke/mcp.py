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
assert len(rpc("tools/list", token=read)["result"]["tools"]) == 25
assert len(rpc("tools/list")["result"]["tools"]) == 61
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
    assert len(rpc("tools/list", token=secret)["result"]["tools"]) == 25
    refreshed, _ = page("/settings/mcp")
    assert secret not in refreshed
    print("MCP browser token issuance, CSRF and one-time disclosure passed")

    permanent_form = {"label": "permanent-browser-token", "scope": "read", "days": "0", "password": os.environ["MCP_TEST_PASSWORD"], "_csrf": csrf(refreshed)}
    created, _ = page("/settings/mcp", permanent_form)
    permanent = re.search(r'tms_mcp_[0-9a-f]{64}', created).group(0)
    assert "No expiration" in created or "Бессрочно" in created
    assert len(rpc("tools/list", token=permanent)["result"]["tools"]) == 25
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
    assert len(tools) == 75
    assert tools["delete_task"]["annotations"]["destructiveHint"]
    assert tools["delete_tasks"]["annotations"]["destructiveHint"]
    assert not tools["delete_tasks"]["annotations"]["readOnlyHint"]
    assert len(rpc("tools/list")["result"]["tools"]) == 61
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

# Recurrence uses the existing schedule and author-only permissions.
future = call("create_task", {"title": "MCP monthly fixture", "deadline": "2030-01-31T12:00:00+09:00", "request_key": "rec-task"})
rid = future["task_id"]
spawn = next(s["id"] for s in call("list_statuses", {})["statuses"] if s["isDefault"] and not s["isCompletion"])
schedule = {"task_id": rid, "mode": "monthly", "spawn_status_id": spawn, "request_key": "rec-monthly"}
rec = call("set_recurrence", schedule)["recurrence"]
assert rec["mode"] == "monthly" and rec["anchorDay"] == 31 and rec["nextDeadline"].startswith("2030-02-28")
assert call("set_recurrence", schedule)["replayed"]
call("set_recurrence", dict(schedule, task_id=tid, request_key="no-deadline"), error=True)
call("set_recurrence", dict(schedule, request_key="rec-foreign"), foreign, error=True)
complete_status = next(s["id"] for s in call("list_statuses", {})["statuses"] if s["isCompletion"])
call("set_recurrence", dict(schedule, spawn_status_id=complete_status, request_key="rec-completed"), error=True)
assert any(t["id"] == rid for t in call("list_tasks", {"deadline_from":"2030-01-31", "deadline_to":"2030-01-31", "without_project":True})["tasks"])
call("list_tasks", {"deadline_from":"2030-02-30"}, error=True)
call("list_tasks", {"deadline_from":"2030-02-01", "deadline_to":"2030-01-01"}, error=True)
assert call("get_task_history", {"task_id":rid})["history"]
call("get_task_history", {"task_id":rid}, foreign, error=True)
assert call("get_project_history", {"project_id":pid})["history"]
call("get_project_history", {"project_id":pid}, foreign, error=True)
if os.environ.get("MCP_TEST_USER"):
    call("delete_recurrence", {"task_id":rid,"confirmed":True,"request_key":"rec-delete"}, error=True)
    call("delete_recurrence", {"task_id":rid,"confirmed":False,"request_key":"rec-delete"}, deletion_token, error=True)
    assert call("delete_recurrence", {"task_id":rid,"confirmed":True,"request_key":"rec-delete"}, deletion_token)["recurrence"] is None
    # Required fields, patch semantics, lineage and rejection of lossy moves.
    required = next(f["id"] for f in call("list_custom_fields", {"project_id":pid})["fields"] if f["name"] == "MCP required fixture")
    filled = call("create_task", {"title":"MCP required filled","project_id":pid,"custom_fields":{str(required):"value"},"request_key":"required-filled"})
    fid=filled["task_id"]
    assert filled["custom_fields"][str(required)] == "value"
    assert call("update_task", {"task_id":fid,"priority":2,"request_key":"field-preserve"})["custom_fields"][str(required)]=="value"
    call("update_task", {"task_id":fid,"custom_fields":{str(required):None},"request_key":"field-clear-required"}, error=True)
    call("update_task", {"task_id":fid,"project_id":None,"request_key":"field-loss"}, error=True)
    assert call("get_task", {"task_id":fid})["custom_fields"][str(required)]=="value"
    field_page,_=page("/custom-fields")
    page("/custom-fields", {"_csrf":csrf(field_page),"name":"MCP lineage fixture","field_type":"text"})
    original=next(f["id"] for f in call("list_custom_fields", {})["fields"] if f["name"]=="MCP lineage fixture")
    dest=call("create_project", {"name":"MCP lineage destination","request_key":"lineage-project"})["project_id"]
    clone=next(f["id"] for f in call("list_custom_fields", {"project_id":dest})["fields"] if f["sourceFieldId"]==original)
    src=call("create_task", {"title":"MCP lineage task","custom_fields":{str(original):"preserve"},"request_key":"lineage-task"})["task_id"]
    moved=call("update_task", {"task_id":src,"project_id":dest,"request_key":"lineage-move"})
    assert moved["custom_fields"][str(clone)]=="preserve"
    assert call("update_task", {"task_id":src,"project_id":None,"request_key":"lineage-back"})["custom_fields"][str(original)]=="preserve"
    call("update_task", {"task_id":src,"custom_fields":{str(clone):"foreign field"},"request_key":"wrong-field"}, error=True)
print("MCP recurrence, history, advanced filters and custom field values passed")

# Reference writes, role changes and generic receipts.
create_ref = {"kind":"task_type","name":"MCP reference type","request_key":"reference-create"}
ref = call("create_reference",create_ref)
typ_id=ref["reference_id"]
assert call("create_reference",create_ref)["reference_id"]==typ_id
assert call("create_reference",create_ref)["replayed"]
call("create_reference",dict(create_ref,name="Different"),error=True)
call("create_reference",dict(create_ref,request_key="read-ref"),read,error=True)
call("update_reference",{"kind":"task_type","reference_id":typ_id,"name":"Forbidden","request_key":"foreign-ref"},foreign,error=True)
call("update_reference",{"kind":"task_type","reference_id":typ_id,"name":"MCP renamed reference","request_key":"ref-rename"})
assert any(t["id"]==typ_id and t["name"]=="MCP renamed reference" for t in call("list_task_types",{})["task_types"])
customer_id=call("create_reference",{"kind":"customer","name":"MCP ref customer","request_key":"customer-ref"})["reference_id"]
status_args={"kind":"status","project_id":pid,"name":"MCP project review","color":"#224466","request_key":"status-ref"}
status_id=call("create_reference",status_args)["reference_id"]
call("create_reference",dict(status_args,request_key="foreign-status-ref"),foreign,error=True)
call("set_status_role",{"project_id":pid,"status_id":status_id,"role":"default","request_key":"status-role"})
assert any(s["id"]==status_id and s["isDefault"] for s in call("list_statuses",{"project_id":pid})["statuses"])
ids=[s["id"] for s in call("list_statuses",{"project_id":pid})["statuses"]]
ordered=list(reversed(ids))
assert call("reorder_references",{"kind":"status","project_id":pid,"reference_ids":ordered,"request_key":"status-order"})["reference_ids"]==ordered
assert [s["id"] for s in call("list_statuses",{"project_id":pid})["statuses"]]==ordered
call("reorder_references",{"kind":"status","project_id":pid,"reference_ids":[status_id],"request_key":"bad-status-order"},error=True)
field=call("create_reference",{"kind":"custom_field","name":"MCP option fixture","field_type":"select","options":["A","B"],"request_key":"ref-field"})["reference_id"]
f=call("create_task",{"title":"MCP ref value","type_id":typ_id,"customer_id":customer_id,"custom_fields":{str(field):"B"},"request_key":"ref-task"})
assert f["custom_fields"][str(field)]=="B"
call("update_reference",{"kind":"custom_field","reference_id":field,"field_type":"text","request_key":"ref-change-type"},error=True)
call("update_reference",{"kind":"custom_field","reference_id":field,"name":"MCP renamed field","request_key":"ref-field-rename"})
assert call("get_task",{"task_id":f["task_id"]})["custom_fields"][str(field)]=="B"
if os.environ.get("MCP_TEST_USER"):
    destructive={"reference_id":field,"field_type":"select","options":["A"],"confirmed":True,"request_key":"ref-prune"}
    call("replace_custom_field_definition",destructive,error=True)
    call("replace_custom_field_definition",dict(destructive,confirmed=False),deletion_token,error=True)
    call("replace_custom_field_definition",destructive,deletion_token)
    assert call("get_task",{"task_id":f["task_id"]})["custom_fields"][str(field)] is None
    call("delete_reference",{"kind":"task_type","reference_id":typ_id,"confirmed":True,"request_key":"used-type"},deletion_token,error=True)
    call("delete_reference",{"kind":"status","project_id":pid,"reference_id":status_id,"confirmed":True,"request_key":"default-status"},deletion_token,error=True)
    delete_field={"kind":"custom_field","reference_id":field,"confirmed":True,"request_key":"delete-field"}
    call("delete_reference",delete_field,error=True)
    call("delete_reference",delete_field,deletion_token)
    assert call("delete_reference",delete_field,deletion_token)["replayed"]
    assert all(x["id"]!=field for x in call("list_custom_fields",{})["fields"])
print("MCP reference management and destructive definition safeguards passed")

a=call("create_task",{"title":"MCP bulk fixture A","request_key":"bulk-a"})["task_id"]
b=call("create_task",{"title":"MCP bulk fixture B","request_key":"bulk-b"})["task_id"]
bulk={"task_ids":[b,a],"priority":3,"type_id":typ_id,"request_key":"bulk-write"}
assert call("update_tasks",bulk)["updated_ids"]==sorted([a,b])
assert call("update_tasks",bulk)["replayed"]
for bad in ([a,a],[a,2147483647]):
    call("update_tasks",dict(bulk,task_ids=bad,priority=0,request_key="bulk-invalid"),error=True)
    assert call("get_task",{"task_id":a})["task"]["priority"]==3
call("update_tasks",dict(bulk,priority=0),error=True)
# A later item with incompatible metadata must roll back an earlier valid update.
if os.environ.get("MCP_TEST_USER") and os.environ.get("MCP_FOREIGN_USER"):
    call("update_tasks",{"task_ids":[task_id,shared_id],"type_id":typ_id,"priority":0,"request_key":"bulk-mixed"},error=True)
    assert call("get_task",{"task_id":task_id})["task"]["priority"]==3
counts=call("summarize_tasks",{"query":"MCP bulk fixture","priority":3})
assert counts["total"]==2 and counts["by_priority"]["3"]==2 and counts["by_project"]["none"]==2
assert call("summarize_tasks",{"query":"MCP bulk fixture"},foreign)["total"]==0
print("MCP atomic bulk updates, replay and scoped analytics passed")

# The same field comparison semantics as the web UI, with strict filter validation.
money=call("create_reference",{"kind":"custom_field","name":"MCP filter money","field_type":"money","request_key":"filter-money"})["reference_id"]
tags=call("create_reference",{"kind":"custom_field","name":"MCP filter tags","field_type":"checkbox_list","options":["red","blue"],"request_key":"filter-tags"})["reference_id"]
check=call("create_reference",{"kind":"custom_field","name":"MCP filter check","field_type":"checkbox","request_key":"filter-check"})["reference_id"]
match=call("create_task",{"title":"MCP filter fixture","custom_fields":{str(money):"100.25",str(tags):["red","blue"],str(check):True},"request_key":"filter-fixture"})
q={"without_project":True,"query":"MCP filter fixture","custom_filters":{str(money):{"min":"100","max":"101"},str(tags):{"values":["red","blue"],"match":"all"},str(check):True}}
assert call("list_tasks",q)["total"]==1
assert call("summarize_tasks",q)["total"]==1
assert call("list_tasks",dict(q,custom_filters={str(money):{"min":"101"}}))["total"]==0
assert call("list_tasks",dict(q,custom_filters={str(tags):["blue"]}))["total"]==1
call("list_tasks",dict(q,custom_filters={str(money):{"min":"101","max":"100"}}),error=True)
call("list_tasks",dict(q,custom_filters={str(tags):{"values":["unknown"],"match":"all"}}),error=True)
call("list_tasks",dict(q,without_project=False),error=True)
call("list_tasks",q,foreign,error=True)
print("MCP custom field range, option and checkbox filters passed")
note=call("create_reference",{"kind":"custom_field","name":"MCP multiline fixture","field_type":"textarea","request_key":"textarea-field"})["reference_id"]
multiline="First line\nSecond line"
entry=call("create_task",{"title":"MCP multiline task","custom_fields":{str(note):multiline},"request_key":"textarea-task"})
assert entry["custom_fields"][str(note)]==multiline
assert call("list_tasks",{"without_project":True,"custom_filters":{str(note):"second line"}})["total"]==1
print("MCP multiline custom field passed")

# MCP team lifecycle, invitations and discussions on disposable users only.
if os.environ.get("MCP_TEST_USER") and os.environ.get("MCP_FOREIGN_USER"):
    team_args={"name":"MCP collaboration fixture","description":"Original","request_key":"collab-team"}
    collab=call("create_team",team_args)["team_id"]
    assert call("create_team",team_args)["replayed"]
    assert call("get_team",{"team_id":collab})["team"]["name"]==team_args["name"]
    call("get_team",{"team_id":collab},foreign,error=True)
    call("update_team",{"team_id":collab,"name":"MCP collaboration renamed","request_key":"collab-rename"})
    invite={"team_id":collab,"identifier":os.environ["MCP_FOREIGN_USER"],"confirmed":True,"request_key":"collab-invite"}
    call("invite_team_member",dict(invite,confirmed=False),error=True)
    invitation=call("invite_team_member",invite)["invitation_id"]
    assert call("invite_team_member",invite)["replayed"]
    assert any(i["id"]==invitation and "invitedEmail" not in i for i in call("list_invitations",{},foreign)["invitations"])
    call("list_invitations",{"team_id":collab},foreign,error=True)
    respond={"invitation_id":invitation,"response":"accept","confirmed":True,"request_key":"collab-accept"}
    call("respond_to_invitation",respond,error=True)
    call("respond_to_invitation",respond,foreign)
    assert call("respond_to_invitation",respond,foreign)["replayed"]
    members=call("get_team",{"team_id":collab},foreign)["members"]
    lead_id=next(m["user_id"] for m in members if m["role"]=="lead")
    collaborator=next(m["user_id"] for m in members if m["role"]=="member")
    assert all("email" not in m for m in members)
    role={"team_id":collab,"user_id":collaborator,"role":"lead","confirmed":True,"request_key":"collab-role"}
    call("change_team_role",role,foreign,error=True)
    call("change_team_role",dict(role,user_id=lead_id,role="member",request_key="last-lead"),error=True)
    proj=call("create_project",{"name":"Collaboration fixture project","team_id":collab,"request_key":"collab-project"})["project_id"]
    task=call("create_task",{"title":"Discussion fixture","project_id":proj,"request_key":"collab-task"})["task_id"]
    root={"task_id":task,"body":"<p>First message</p><script>bad()</script>","request_key":"collab-comment"}
    comment=call("add_comment",root)["comment_id"]
    assert call("add_comment",root)["replayed"]
    replies=call("list_comments",{"task_id":task},foreign)
    assert replies["total"]==1 and "<script>" not in replies["comments"][0]["bodyHtml"]
    reply=call("add_comment",{"task_id":task,"body":"Reply","parent_comment_id":comment,"request_key":"collab-reply"},foreign)["comment_id"]
    call("add_comment",{"project_id":proj,"body":"Wrong context","parent_comment_id":comment,"request_key":"wrong-parent"},error=True)
    call("update_comment",{"task_id":task,"comment_id":comment,"body":"Forbidden","request_key":"wrong-author"},foreign,error=True)
    call("update_comment",{"task_id":task,"comment_id":reply,"body":"Updated reply","request_key":"reply-edit"},foreign)
    assert call("list_comments",{"task_id":task,"limit":1})["next_offset"]==1
    call("mark_discussion_read",{"project_id":proj,"request_key":"collab-read"},foreign)
    call("delete_comment",{"task_id":task,"comment_id":reply,"confirmed":True,"request_key":"collab-remove-comment"},error=True)
    call("delete_comment",{"task_id":task,"comment_id":reply,"confirmed":True,"request_key":"collab-remove-comment"},deletion_token)
    call("delete_team",{"team_id":collab,"confirmed":True,"request_key":"collab-delete-protected"},deletion_token,error=True)
    call("remove_team_member",{"team_id":collab,"user_id":collaborator,"confirmed":True,"request_key":"collab-remove-member"},deletion_token)
    call("list_comments",{"task_id":task},foreign,error=True)
    empty=call("create_team",{"name":"Empty team fixture","request_key":"empty-team"})["team_id"]
    call("delete_team",{"team_id":empty,"confirmed":True,"request_key":"empty-team-delete"},deletion_token)
    # Exercise remaining membership transitions, including replay after leaving.
    life=call("create_team",{"name":"Membership lifecycle fixture","request_key":"membership-team"})["team_id"]
    iv={"team_id":life,"identifier":os.environ["MCP_FOREIGN_USER"],"confirmed":True,"request_key":"lifecycle-invite-1"}
    i1=call("invite_team_member",iv)["invitation_id"]
    revoke={"team_id":life,"invitation_id":i1,"confirmed":True,"request_key":"lifecycle-revoke"}
    call("revoke_invitation",revoke,error=True)
    call("revoke_invitation",revoke,deletion_token)
    call("respond_to_invitation",{"invitation_id":i1,"response":"accept","confirmed":True,"request_key":"revoked-accept"},foreign,error=True)
    i2=call("invite_team_member",dict(iv,request_key="lifecycle-invite-2"))["invitation_id"]
    call("respond_to_invitation",{"invitation_id":i2,"response":"decline","confirmed":True,"request_key":"lifecycle-decline"},foreign)
    i3=call("invite_team_member",dict(iv,request_key="lifecycle-invite-3"))["invitation_id"]
    call("respond_to_invitation",{"invitation_id":i3,"response":"accept","confirmed":True,"request_key":"lifecycle-accept"},foreign)
    change={"team_id":life,"user_id":collaborator,"role":"lead","confirmed":True,"request_key":"lifecycle-promote"}
    call("change_team_role",change)
    assert any(m["user_id"]==collaborator and m["role"]=="lead" for m in call("get_team",{"team_id":life})["members"])
    leave={"team_id":life,"confirmed":True,"request_key":"lifecycle-leave"}
    call("leave_team",leave,error=True)
    call("leave_team",leave,deletion_token)
    assert call("leave_team",leave,deletion_token)["replayed"]
    call("get_team",{"team_id":life},error=True)
    assert call("get_team",{"team_id":life},foreign)["team"]["currentUserRole"]=="lead"
    print("MCP team membership, invitation and discussion safeguards passed")

# Attachments use the existing file type policy and never expose storage paths.
import base64
content=b"MCP disposable attachment fixture.\n"
encoded=base64.b64encode(content).decode()
upload={"task_id":task_id,"filename":"fixture.txt","content_base64":encoded,"request_key":"mcp-upload"}
aid=call("upload_attachment",upload)["attachment_id"]
assert call("upload_attachment",upload)["replayed"]
files=call("list_attachments",{"task_id":task_id})
assert any(x["id"]==aid and "storageName" not in x and "storage_name" not in x for x in files["attachments"])
part=call("read_attachment",{"task_id":task_id,"attachment_id":aid,"length":5})
assert base64.b64decode(part["content_base64"])==content[:5] and part["next_offset"]==5
tail=call("read_attachment",{"task_id":task_id,"attachment_id":aid,"offset":5})
assert base64.b64decode(tail["content_base64"])==content[5:] and tail["next_offset"] is None
call("read_attachment",{"task_id":task_id,"attachment_id":aid},foreign,error=True)
call("upload_attachment",dict(upload,filename="fixture.exe",request_key="blocked-upload"),error=True)
call("upload_attachment",dict(upload,content_base64="!!!!",request_key="bad-upload"),error=True)
pa=call("upload_attachment",{"project_id":pid,"filename":"project.txt","content_base64":encoded,"request_key":"mcp-project-file"})["attachment_id"]
assert base64.b64decode(call("read_attachment",{"project_id":pid,"attachment_id":pa})["content_base64"])==content
call("list_attachments",{"project_id":pid,"task_id":task_id},error=True)
if os.environ.get("MCP_TEST_USER"):
    remove={"task_id":task_id,"attachment_id":aid,"confirmed":True,"request_key":"mcp-file-delete"}
    call("delete_attachment",remove,error=True)
    call("delete_attachment",dict(remove,confirmed=False),deletion_token,error=True)
    deleted=call("delete_attachment",remove,deletion_token)
    assert deleted["attachment_cleanup_pending"]==0 and "_cleanup_key" not in deleted
    assert call("delete_attachment",remove,deletion_token)["replayed"]
    call("read_attachment",{"task_id":task_id,"attachment_id":aid},error=True)
    call("delete_attachment",{"project_id":pid,"attachment_id":pa,"confirmed":True,"request_key":"mcp-project-file-delete"},deletion_token)
print("MCP attachment upload, chunk reads, isolation, validation and cleanup passed")

# Workspace operations use real web saved-view queries, user settings and calendar.
view_args={"name":"MCP workspace view","query":"project=all&priority=high&q=fixture&sort=deadline&order=asc&untrusted=discard","is_default":True,"request_key":"workspace-view"}
vid=call("create_saved_view",view_args)["view_id"]
assert call("create_saved_view",view_args)["replayed"]
view=call("get_saved_view",{"view_id":vid})["view"]
assert view["query"]["project"]=="all" and view["query"]["priority"]=="high"
assert "untrusted" not in view["query"] and view["isDefault"]
call("get_saved_view",{"view_id":vid},foreign,error=True)
assert all(v["id"]!=vid for v in call("list_saved_views",{},foreign)["views"])
view2=call("create_saved_view",{"name":"MCP alternate view","query":"q=alternate","request_key":"workspace-view-2"})["view_id"]
call("set_default_saved_view",{"view_id":view2,"request_key":"workspace-view-default"})
assert call("get_saved_view",{"view_id":view2})["view"]["isDefault"]
assert not call("get_saved_view",{"view_id":vid})["view"]["isDefault"]
call("set_default_saved_view",{"view_id":vid,"request_key":"workspace-view-foreign"},foreign,error=True)
daytask=call("create_task",{"title":"Calendar fixture","scheduled_at":"2031-03-02T10:00:00+09:00","deadline":"2031-03-04T12:00:00+09:00","request_key":"workspace-cal-task"})["task_id"]
for mode,date in [("planned_only","2031-03-02"),("deadlines_only","2031-03-04")]:
    result=call("list_calendar",{"from":date,"to":date,"mode":mode,"without_project":True})
    assert any(t["id"]==daytask for t in result["tasks"]),result
    assert all(t["id"]!=daytask for t in call("list_calendar",{"from":date,"to":date,"mode":mode},foreign)["tasks"])
call("list_calendar",{"from":"2031-02-30","to":"2031-03-01"},error=True)
call("list_calendar",{"from":"2031-03-02","to":"2030-03-01"},error=True)
call("list_calendar",{"from":"2031-01-01","to":"2033-01-01"},error=True)
old_pref=call("get_preferences",{})["preferences"]
change={"theme":"midnight","confirmed":True,"request_key":"workspace-pref"}
call("update_preferences",dict(change,confirmed=False),error=True)
call("update_preferences",change)
assert call("get_preferences",{})["preferences"]["theme"]=="midnight"
assert call("get_preferences",{},foreign)["preferences"]["theme"]!="midnight"
assert call("get_preferences",{})["preferences"]["timezone"]==old_pref["timezone"]
call("update_preferences",{"timezone":"Invalid/Timezone","confirmed":True,"request_key":"workspace-invalid-tz"},error=True)
old_settings=call("get_notification_settings",{})["settings"]
assert "email_address" not in old_settings and "telegram_chat_id" not in old_settings
patch={"notify_tomorrow":True,"tomorrow_time":"10:15","confirmed":True,"request_key":"workspace-notifications"}
call("update_notification_settings",dict(patch,confirmed=False),error=True)
call("update_notification_settings",patch)
settings=call("get_notification_settings",{})["settings"]
assert settings["notify_tomorrow"] and settings["tomorrow_time"]=="10:15"
assert settings["high_minutes"]==old_settings["high_minutes"]
call("update_notification_settings",{"tomorrow_time":"25:00","confirmed":True,"request_key":"workspace-invalid-time"},error=True)
assert call("get_notification_settings",{},foreign)["settings"]["tomorrow_time"]!="10:15"
inbox=call("list_notifications",{},read)
assert "notifications" in inbox and "unread" in inbox
if inbox["notifications"]:
    nid=inbox["notifications"][0]["id"]
    call("mark_notification_read",{"notification_id":nid,"request_key":"workspace-notification-foreign"},foreign,error=True)
    call("mark_notification_read",{"notification_id":nid,"request_key":"workspace-notification-read"})
call("mark_all_notifications_read",{"confirmed":False,"request_key":"workspace-all-read"},error=True)
call("mark_all_notifications_read",{"confirmed":True,"request_key":"workspace-all-read"})
assert call("list_notifications",{})["unread"]==0
assert call("restore_default_statuses",{"request_key":"workspace-restore"})["created_count"]>=0
assert call("restore_default_statuses",{"request_key":"workspace-restore"})["replayed"]
print("MCP saved views, calendar, notification inbox, preferences and defaults passed")

if os.environ.get("MCP_TEST_USER"):
    delete_view={"view_id":vid,"confirmed":True,"request_key":"workspace-delete-view"}
    call("delete_saved_view",delete_view,error=True)
    call("delete_saved_view",dict(delete_view,confirmed=False),deletion_token,error=True)
    call("delete_saved_view",delete_view,deletion_token)
    assert call("delete_saved_view",delete_view,deletion_token)["replayed"]
    call("get_saved_view",{"view_id":vid},error=True)
    transfer_id=call("create_project",{"name":"Transfer workspace fixture","request_key":"workspace-transfer-project"})["project_id"]
    team_id=call("create_team",{"name":"Transfer workspace team","request_key":"workspace-transfer-team"})["team_id"]
    tr={"project_id":transfer_id,"team_id":team_id,"confirmed":True,"request_key":"workspace-transfer"}
    call("transfer_project",dict(tr,confirmed=False),error=True)
    call("transfer_project",tr,foreign,error=True)
    call("transfer_project",tr)
    assert call("transfer_project",tr)["replayed"]
    assert call("get_project",{"project_id":transfer_id})["project"]["ownerTeamId"]==team_id
    call("transfer_project",dict(tr,team_id=None,request_key="workspace-transfer-back"))
    assert call("get_project",{"project_id":transfer_id})["project"]["ownerTeamId"] is None
    call("upload_attachment",{"project_id":transfer_id,"filename":"cleanup.txt","content_base64":encoded,"request_key":"workspace-delete-file"})
    delete={"project_id":transfer_id,"confirmed":True,"request_key":"workspace-delete-project"}
    call("delete_project",delete,error=True)
    call("delete_project",dict(delete,confirmed=False),deletion_token,error=True)
    removed=call("delete_project",delete,deletion_token)
    assert removed["attachment_cleanup_pending"]==0 and "_cleanup_keys" not in removed
    assert call("delete_project",delete,deletion_token)["replayed"]
    call("get_project",{"project_id":transfer_id},error=True)
    # Existing project with tasks must not be deleted accidentally.
    call("delete_project",{"project_id":pid,"confirmed":True,"request_key":"workspace-delete-nonempty"},deletion_token,error=True)
    assert call("get_project",{"project_id":pid})["project"]["id"]==pid
    print("MCP project ownership, deletion, cleanup and access safeguards passed")

# Resumable uploads survive independent requests and are verified before publication.
import hashlib
payload=b"Resumable attachment fixture line.\n"*9000
digest=hashlib.sha256(payload).hexdigest()
start={"task_id":task_id,"filename":"resumable.txt","size":len(payload),"sha256":digest,"request_key":"chunk-start"}
up=call("begin_attachment_upload",start)
upid=up["upload_id"]
assert call("begin_attachment_upload",start)["upload_id"]==upid
assert call("get_attachment_upload",{"upload_id":upid},read)["next_offset"]==0
call("get_attachment_upload",{"upload_id":upid},foreign,error=True)
call("begin_attachment_upload",dict(start,filename="bad.exe",request_key="bad-extension"),error=True)
call("begin_attachment_upload",dict(start,size=2147483647,request_key="oversized"),error=True)
finish={"upload_id":upid,"request_key":"chunk-finish"}
call("finish_attachment_upload",finish,error=True)
chunk_size=up["chunk_max_bytes"]
assert len(payload)>chunk_size
for offset in range(0,len(payload),chunk_size):
    chunk={"upload_id":upid,"offset":offset,"content_base64":base64.b64encode(payload[offset:offset+chunk_size]).decode(),"request_key":"chunk-"+str(offset)}
    if offset==0:
        call("append_attachment_chunk",dict(chunk,offset=1,request_key="chunk-wrong-offset"),error=True)
        call("append_attachment_chunk",dict(chunk,content_base64=base64.b64encode(b"tiny").decode(),request_key="chunk-tiny"),error=True)
        call("append_attachment_chunk",chunk,foreign,error=True)
    ack=call("append_attachment_chunk",chunk)
    assert ack["next_offset"]==min(len(payload),offset+chunk_size)
    assert call("append_attachment_chunk",chunk)["replayed"]
    assert call("get_attachment_upload",{"upload_id":upid})["next_offset"]==ack["next_offset"]
published=call("finish_attachment_upload",finish)
assert call("finish_attachment_upload",finish)["replayed"]
aid=published["attachment_id"]
call("get_attachment_upload",{"upload_id":upid},error=True)
downloaded=b""
offset=0
while offset is not None:
    data=call("read_attachment",{"task_id":task_id,"attachment_id":aid,"offset":offset})
    downloaded+=base64.b64decode(data["content_base64"])
    offset=data["next_offset"]
assert downloaded==payload
bad=call("begin_attachment_upload",{"task_id":task_id,"filename":"bad-hash.txt","size":len(content),"sha256":"0"*64,"request_key":"bad-hash-start"})["upload_id"]
call("append_attachment_chunk",{"upload_id":bad,"offset":0,"content_base64":encoded,"request_key":"bad-hash-bytes"})
call("finish_attachment_upload",{"upload_id":bad,"request_key":"bad-hash-finish"},error=True)
cancel={"upload_id":bad,"request_key":"bad-hash-cancel"}
call("cancel_attachment_upload",cancel,foreign,error=True)
call("cancel_attachment_upload",cancel)
assert call("cancel_attachment_upload",cancel)["replayed"]
call("get_attachment_upload",{"upload_id":bad},error=True)
if os.environ.get("MCP_TEST_USER"):
    call("delete_attachment",{"task_id":task_id,"attachment_id":aid,"confirmed":True,"request_key":"chunk-delete"},deletion_token)
print("MCP resumable attachment upload, verification, isolation and replay passed")
