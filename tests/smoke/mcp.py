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
assert len(rpc("tools/list", token=read)["result"]["tools"]) == 4
assert len(rpc("tools/list")["result"]["tools"]) == 9
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
    assert len(rpc("tools/list", token=secret)["result"]["tools"]) == 4
    refreshed, _ = page("/settings/mcp")
    assert secret not in refreshed
    print("MCP browser token issuance, CSRF and one-time disclosure passed")
