"""OAuth authorization-code, registration, scope, revocation and replay checks on a disposable TMS."""
import base64
import hashlib
import http.cookiejar
import json
import os
import re
import secrets
import urllib.error
import urllib.parse
import urllib.request

site = os.environ["APP_URL"].rstrip("/")
resource = site + "/mcp"
callback = "https://oauth-test.invalid/callback?existing=1"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def browser():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


def req(path, data=None, content_type=None, expected=200, opener=None, headers=None):
    h = dict(headers or {})
    if content_type:
        h["Content-Type"] = content_type
    r = urllib.request.Request(site + path, data, h)
    try:
        response = (opener or urllib.request.build_opener(NoRedirect())).open(r, timeout=20)
    except urllib.error.HTTPError as exc:
        response = exc
    with response:
        body = response.read()
        assert response.status == expected, (path, response.status, body[:300])
        return body, response.headers


def jget(path):
    body, _ = req(path)
    return json.loads(body)


def post_json(path, data, expected=200, headers=None):
    body, h = req(path, json.dumps(data).encode(), "application/json", expected, headers=headers)
    return json.loads(body) if body else {}, h


def form(path, args, expected=200, opener=None, headers=None):
    return req(path, urllib.parse.urlencode(args).encode(), "application/x-www-form-urlencoded", expected, opener, headers)


def register(method="none", name="OAuth test fixture", redirect=callback):
    result, _ = post_json("/oauth/register", {
        "client_name": name, "redirect_uris": [redirect], "token_endpoint_auth_method": method,
        "grant_types": ["authorization_code", "refresh_token"], "response_types": ["code"]
    }, 201)
    assert result["redirect_uris"] == [redirect]
    return result


def rpc(method, args=None, token=None, expected=200):
    h = {"Accept": "application/json, text/event-stream"}
    if token:
        h["Authorization"] = "Bearer " + token
    return post_json("/mcp", {"jsonrpc": "2.0", "id": 1, "method": method, "params": args or {}}, expected, h)


def tool(name, args, token):
    result, _ = rpc("tools/call", {"name": name, "arguments": args}, token)
    assert not result["result"].get("isError"), name
    return json.loads(result["result"]["content"][0]["text"])


def tools(token):
    return {t["name"] for t in rpc("tools/list", token=token)[0]["result"]["tools"]}


def params(client, scope="tms:read"):
    verifier = secrets.token_urlsafe(64)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip("=")
    return verifier, {
        "client_id": client["client_id"], "redirect_uri": callback, "response_type": "code",
        "resource": resource, "state": secrets.token_urlsafe(24), "scope": scope,
        "code_challenge": challenge, "code_challenge_method": "S256",
    }


def hidden(body, name):
    return re.search(rb'name="' + name.encode() + rb'" value="([^"]+)"', body).group(1).decode()


def consent(client, scope="tms:read", user=None, password=None, deny=False):
    verifier, p = params(client, scope)
    b = browser()
    page, h = req("/oauth/authorize?" + urllib.parse.urlencode(p), opener=b)
    assert h["Cache-Control"] == "no-store" and h["X-Frame-Options"] == "DENY"
    assert b"oauth-test.invalid" in page
    fields = {"_csrf": hidden(page, "_csrf"), "nonce": hidden(page, "nonce"),
              "username": user or os.environ["MCP_TEST_USER"], "password": password or os.environ["MCP_TEST_PASSWORD"],
              "decision": "deny" if deny else "allow"}
    form("/oauth/authorize", dict(fields, _csrf="invalid"), 403, b)
    if not deny:
        form("/oauth/authorize", dict(fields, password="incorrect"), 401, b)
    _, response = form("/oauth/authorize", fields, 303, b)
    location = urllib.parse.urlsplit(response["Location"])
    assert location.hostname == "oauth-test.invalid"
    query = urllib.parse.parse_qs(location.query)
    assert query["state"] == [p["state"]] and query["iss"] == [site] and query["existing"] == ["1"]
    form("/oauth/authorize", fields, 400, b)
    if deny:
        assert query["error"] == ["access_denied"] and "code" not in query
        return None
    return {"grant_type": "authorization_code", "client_id": client["client_id"], "redirect_uri": callback,
            "resource": resource, "code": query["code"][0], "code_verifier": verifier}


def token(fields, expected=200, headers=None):
    body, h = form("/oauth/token", fields, expected, headers=headers)
    assert h["Cache-Control"] == "no-store"
    return json.loads(body)


meta = jget("/.well-known/oauth-protected-resource/mcp")
assert meta == jget("/.well-known/oauth-protected-resource")
assert meta["resource"] == resource and meta["authorization_servers"] == [site]
auth = jget("/.well-known/oauth-authorization-server")
assert auth["issuer"] == site and auth["code_challenge_methods_supported"] == ["S256"]
_, h = rpc("initialize", expected=401)
assert "resource_metadata=" in h["WWW-Authenticate"]
post_json("/oauth/register", {"redirect_uris": ["http://evil.invalid/cb"]}, 400)
post_json("/oauth/register", {"redirect_uris": ["https://user@evil.invalid/cb"]}, 400)
post_json("/oauth/register", {"redirect_uris": ["https://evil.invalid/cb#fragment"]}, 400)
post_json("/oauth/register", {"redirect_uris": ["https://evil.invalid/cb?code=x"]}, 400)
client = register()
other = register(name="Other OAuth fixture")
assert "client_secret" not in client
_, p = params(client)
for change in [{"redirect_uri":"https://evil.invalid/cb"}, {"code_challenge_method":"plain"}, {"resource":"https://elsewhere.invalid/mcp"}, {"scope":"admin"}]:
    req("/oauth/authorize?" + urllib.parse.urlencode(dict(p, **change)), expected=400)
req("/oauth/authorize?" + urllib.parse.urlencode(p) + "&client_id=" + other["client_id"], expected=400)
req("/oauth/token", b"grant_type=refresh_token&grant_type=authorization_code", "application/x-www-form-urlencoded", 400)
consent(client, deny=True)
code = consent(client, "tms:write")
assert token(dict(code, code_verifier="x"*43), 400)["error"] == "invalid_grant"
assert token(dict(code, client_id=other["client_id"]), 400)["error"] == "invalid_grant"
assert token(dict(code, resource="https://wrong.invalid/mcp"), 400)["error"] == "invalid_target"
assert token(dict(code, redirect_uri="https://evil.invalid/cb"), 400)["error"] == "invalid_grant"
grant = token(code)
access = grant["access_token"]
refresh = grant["refresh_token"]
assert grant["scope"] == "tms:read tms:write" and grant["expires_in"] == 3600
assert len(tools(access)) == 61
assert rpc("initialize", {"protocolVersion":"2025-11-25"}, access)[0]["result"]["protocolVersion"] == "2025-11-25"
task_args = {"title":"OAuth disposable fixture", "request_key":"oauth-fixture"}
created = tool("create_task",task_args,access)
assert tool("create_task",task_args,access)["replayed"]
_, h = rpc("tools/call", {"name":"delete_task","arguments":{"task_id":created["task_id"]}}, access, 403)
assert "insufficient_scope" in h["WWW-Authenticate"] and "tms:delete" in h["WWW-Authenticate"]
ref = {"grant_type":"refresh_token","client_id":client["client_id"],"resource":resource,"refresh_token":refresh}
assert token(dict(ref, scope="tms:delete"),400)["error"] == "invalid_scope"
assert token(dict(ref, client_id=other["client_id"]),400)["error"] == "invalid_grant"
rotated = token(ref)
assert rotated["access_token"] != access and rotated["refresh_token"] != refresh
rpc("tools/list", token=access, expected=401)
assert tool("create_task",task_args,rotated["access_token"])["replayed"]
# Refresh-token replay revokes the grant, including the replacement token.
assert token(ref,400)["error"] == "invalid_grant"
rpc("tools/list", token=rotated["access_token"], expected=401)
# Narrowing scopes during refresh is allowed but cannot be undone by refreshing again.
narrow_grant=token(consent(client,"tms:write"))
narrow_ref={"grant_type":"refresh_token","client_id":client["client_id"],"refresh_token":narrow_grant["refresh_token"],"scope":"tms:read"}
narrowed=token(narrow_ref)
assert narrowed["scope"]=="tms:read" and len(tools(narrowed["access_token"]))==25
assert token(dict(narrow_ref,refresh_token=narrowed["refresh_token"],scope="tms:write"),400)["error"]=="invalid_scope"
form("/oauth/revoke",{"client_id":client["client_id"],"token":narrowed["refresh_token"]})
# Read token is isolated to the explicitly consenting account.
read_grant=token(consent(other, "tms:read",os.environ["MCP_FOREIGN_USER"],os.environ["MCP_FOREIGN_PASSWORD"]))
assert len(tools(read_grant["access_token"]))==25
assert tool("list_tasks",{"query":"OAuth disposable fixture"},read_grant["access_token"])["total"]==0
# Code replay revokes a valid redeemed grant.
code2=consent(client, "tms:read")
grant2=token(code2)
assert token(code2,400)["error"]=="invalid_grant"
rpc("tools/list",token=grant2["access_token"],expected=401)
# Confidential client token authentication methods.
for method in ["client_secret_basic","client_secret_post"]:
    confidential=register(method,name="OAuth confidential "+method)
    code3=consent(confidential, "tms:delete")
    assert token(code3,401)["error"]=="invalid_client"
    if method=="client_secret_basic":
        headers={"Authorization":"Basic "+base64.b64encode((confidential["client_id"]+":"+confidential["client_secret"]).encode()).decode()}
        secret_args={}
    else:
        headers={}
        secret_args={"client_secret":confidential["client_secret"]}
    granted=token(dict(code3,**secret_args),headers=headers)
    assert len(tools(granted["access_token"]))==75
    form("/oauth/revoke",{"client_id":confidential["client_id"],"token":granted["refresh_token"],**secret_args},headers=headers)
    rpc("tools/list",token=granted["access_token"],expected=401)
# Revoke OAuth connection via the same UI as manual tokens.
active=token(consent(client,"tms:read"))
b=browser()
login,_=req("/login",opener=b)
form("/login",{"_csrf":hidden(login,"_csrf"),"username":os.environ["MCP_TEST_USER"],"password":os.environ["MCP_TEST_PASSWORD"]},302,b)
settings,_=req("/settings/mcp",opener=b)
rows=re.findall(rb"<tr>.*?</tr>",settings,re.S)
row=next(r for r in rows if b"OAuth: OAuth test fixture" in r and b"/revoke" in r)
path=re.search(rb'action="(/settings/mcp/[0-9]+/revoke)"',row).group(1).decode()
form(path,{"_csrf":hidden(settings,"_csrf")},303,b)
rpc("tools/list",token=active["access_token"],expected=401)
assert token({"grant_type":"refresh_token","client_id":client["client_id"],"refresh_token":active["refresh_token"]},400)["error"]=="invalid_grant"
# Legacy credentials still work.
assert len(tools(os.environ["MCP_WRITE_TOKEN"]))==61
print("MCP OAuth: discovery, DCR, consent, PKCE, scopes, isolation, rotation, replay and revocation passed")
