#!/usr/bin/env python3
"""PreToolUse guard for Claude Code on the shared dev+prod host (103.82.27.129).

Installed machine-wide as /etc/claude-code/hooks/host_guard.py and wired from
/etc/claude-code/managed-settings.json, so it runs in every directory and worktree and no
user/project setting can switch it off. This file in the repo is the reviewed copy; the
tests next to it (test_host_guard.py) pin every rule, including common evasions.

For the tools Claude calls (Bash, Read, Edit, Write, MultiEdit, NotebookEdit, Glob, Grep) it
denies:
  - installing/removing system software: apt/apt-get/dpkg/snap, pip outside a virtualenv,
    npm/pnpm/yarn/bun global installs, curl|sh and friends;
  - writing, deleting, chmod/chown-ing anything in the prod checkout;
  - reading prod secrets (.env*, DB dumps and backups, private uploads) and dev/.env;
  - docker against the prod project or its containers, except ps, logs and env-free inspect;
  - host administration (cron, systemd, users, sudoers, firewall);
  - editing the guard itself, any Claude settings file or hook directory;
  - running the prod deploy/rollback scripts (the owner runs those via `!`).

Scope, stated plainly: this stops MISTAKES and most prompt-injection. It is not a boundary
against a root process that sets out to evade it (Claude runs as root on this host); the
real fix is running Claude unprivileged. Exit code 2 = deny with the reason on stderr. Any
internal error also denies, and the settings entry appends `|| exit 2` so even a missing
interpreter fails closed.
"""

import glob as globmod
import json
import os
import re
import shlex
import shutil
import sys
import warnings

warnings.simplefilter("ignore")  # regex FutureWarnings must not leak into the hook's stderr

PROD = "/opt/bswebsaas/prod"
BSW = "/opt/bswebsaas"
DEV = "/opt/bswebsaas/dev"
HOME = os.path.expanduser("~")

# dev/.env carries the Cloudflare/R2 keys of the prod backup bucket, so it is treated as a
# prod secret. Only explicit references are caught (see SECRET_DIRS for why).
PROTECT_DEV_ENV = True

GUARD_DIRS = ("/etc/claude-code",)
SYSTEM_WRITE_ROOTS = (
    "/etc/sudoers.d", "/etc/crontab", "/etc/cron.d", "/etc/cron.hourly", "/etc/cron.daily",
    "/etc/cron.weekly", "/etc/cron.monthly", "/var/spool/cron", "/etc/systemd", "/etc/passwd",
    "/etc/shadow", "/etc/group", "/etc/gshadow", "/etc/apt", "/etc/ufw", "/etc/nftables.conf",
    "/etc/iptables", "/etc/docker", "/root/.ssh",
)
# Directories whose recursive read (grep -r, tar, cp -r, rg …) would sweep up a secret.
# dev is deliberately absent: grep -r in the dev repo is everyday work, rg/Grep skip the
# hidden gitignored .env, and the managed Read() deny covers the Grep tool there.
SECRET_DIRS = (PROD, PROD + "/db-backups", PROD + "/storage/app/private", BSW + "/db-backups", BSW)
# Deleting / moving / chmod -R-ing one of these, or an ancestor, reaches protected files.
PROTECTED_ROOTS = (PROD, "/etc/claude-code", HOME + "/.claude", "/root/.claude", BSW + "/db-backups")

PROD_NAME_RE = re.compile(r"bsweb[-_]prod")
DEPLOY_SCRIPTS = {"prod-deploy.sh", "prod-rollback.sh"}
SUBST = "__HOSTGUARD_SUBST__"

SYSADMIN = {
    "crontab", "systemctl", "service", "useradd", "usermod", "userdel", "groupadd", "groupmod",
    "groupdel", "gpasswd", "passwd", "chpasswd", "adduser", "deluser", "addgroup", "delgroup",
    "visudo", "sudoedit", "iptables", "ip6tables", "iptables-restore", "ip6tables-restore",
    "iptables-legacy", "iptables-nft", "nft", "ufw", "firewall-cmd", "chsh", "chage", "vipw",
    "vigr", "update-rc.d", "systemd-run", "at", "batch",
}
SHELLS = {"sh", "bash", "zsh", "dash", "ksh", "fish"}
INTERP_RE = re.compile(r"^(python\d*(\.\d+)?|node|nodejs|perl|ruby|php\d*(\.\d+)?|bun|deno)$")
META_ONLY = {"ls", "ll", "stat", "test", "[", "[[", "du", "realpath", "readlink", "basename",
             "dirname", "tree", "namei"}
# Commands whose arguments are data, never files they open.
DATA_ARGS = {"echo", "printf", "true", "false", ":", "date", "sleep", "seq", "id", "whoami",
             "hostname", "uname", "ps", "pgrep", "which", "type", "ping", "dig", "host",
             "nslookup", "getent", "lastlog", "last", "who", "uptime", "free", "nproc", "lscpu",
             "tput", "clear", "printenv", "env", "pwd", "exit", "return", "wait", "set",
             "shopt", "trap", "unset", "local", "declare", "readonly"}
# Content readers: may name prod files (not secrets); a few flags turn them into writers.
RO_CMDS = {
    "cat", "tac", "head", "tail", "less", "more", "grep", "egrep", "fgrep", "zgrep", "rg", "ag",
    "ack", "wc", "file", "diff", "cmp", "comm", "md5sum", "sha1sum", "sha256sum", "sha512sum",
    "b2sum", "cksum", "jq", "yq", "awk", "gawk", "mawk", "sed", "sort", "uniq", "cut", "tr",
    "column", "nl", "od", "xxd", "hexdump", "strings", "base64", "base32", "zcat", "zless",
    "bzcat", "xzcat", "curl", "wget", "df", "lsof", "ss", "netstat", "fold", "fmt", "paste",
    "join", "expand", "rev", "lsblk", "findmnt", "journalctl", "sum",
}
PATTERN_FIRST = {"grep", "egrep", "fgrep", "zgrep", "rg", "ag", "ack", "sed", "awk", "gawk",
                 "mawk", "jq", "yq"}
RECURSIVE_READERS = {"rg", "ag", "ack"}  # recursive by default on a directory
COPY_LIKE = {"cp", "scp", "install", "rsync", "ln", "tar", "zip", "7z", "7za", "unzip"}
DESTRUCTIVE = {"rm", "mv", "chmod", "chown", "chgrp", "chattr", "shred", "rsync", "setfacl"}
GIT_RO = {
    "status", "log", "show", "diff", "rev-parse", "describe", "ls-files", "ls-tree", "cat-file",
    "blame", "shortlog", "reflog", "merge-base", "for-each-ref", "name-rev", "check-ignore",
    "rev-list", "ls-remote", "grep", "show-ref", "whatchanged", "count-objects", "help",
    "version", "branch", "tag", "remote", "config", "worktree", "stash",
}
PKG_NAMES = {"apt", "apt-get", "aptitude", "dpkg", "snap", "yum", "dnf", "apk", "zypper", "pacman",
             "rpm", "easy_install", "pipx", "pip", "pip3", "npm", "pnpm", "yarn", "bun", "uv"}
# Names we still recognise when reached through a renamed symlink or PATH alias.
WATCHED = SYSADMIN | PKG_NAMES | DEPLOY_SCRIPTS | SHELLS | {"docker", "docker-compose", "git"}
DOCKER_MUTATING = {"stop", "kill", "rm", "restart", "pause", "unpause", "update", "rmi", "down",
                   "start", "exec", "cp", "commit", "run"}

OPS = ["&>>", ">>", "<<<", "<<", "&&", "||", "|&", ";;", ">&", "<&", "&>", ">|", "<>",
       ">", "<", "|", "&", ";", "(", ")"]
SEPARATORS = {";", "&&", "||", "|", "|&", "&", ";;", "(", ")"}
PIPES = {"|", "|&"}
REDIR_OUT = {">", ">>", ">|", "&>", "&>>", "<>"}
ASSIGN_RE = re.compile(r"^([A-Za-z_][A-Za-z0-9_]*)=(.*)$", re.S)
HEREDOC_RE = re.compile(r"(?<!<)<<(?!<)(-?)\s*([\"']?)([A-Za-z_][A-Za-z0-9_]*)\2")
HEREDOC_SHELL_RE = re.compile(r"(^|[\s;&|(])(sudo\s+)?(\S*/)?(ba|z|da|k)?sh(\s|$)")
HEREDOC_CODE_RE = re.compile(r"(^|[\s;&|(])(\S*/)?(python\d*(\.\d+)?|node|perl|ruby|php)(\s|$)")
# A download piped into an interpreter that reads its PROGRAM from stdin (no script/-c/-m).
PIPE_TO_SHELL_RE = re.compile(
    r"\b(curl|wget|fetch|aria2c)\b[^;&\n]*\|\s*(sudo\s+(-\S+\s+)*)?(\S*/)?"
    r"((ba|z|da|k)?sh|python\d*(\.\d+)?|perl|ruby|node|php)(\s+-[a-z]*)*\s*($|[;&|)])")
SHELL_SUBST_FETCH_RE = re.compile(
    r"(\b(ba|z|da|k)?sh\s+(-\w+\s+)*-\w*c\w*\s+[\"']?|\beval\s+[\"']?|\b(source|\.|(ba|z|da|k)?sh)\s+)"
    r"(\$\(|<\(|`)\s*(curl|wget|fetch)\b")
PKG_IN_CODE_RE = re.compile(
    r"\b(apt|apt-get|aptitude|dpkg|snap|yum|dnf)\b\W+(-\S+\W+)*(install|remove|purge|-i|-r|-P)\b")


class Deny(Exception):
    """A refused tool call: rule id + a reason the owner can read."""

    def __init__(self, rule, why):
        super().__init__(f"{rule}: {why}")
        self.rule = rule
        self.why = why


# --------------------------------------------------------------------------- paths

def under(path, root):
    return path == root or path.startswith(root.rstrip("/") + "/")


def secret_kind(p):
    base, parent = os.path.basename(p), os.path.dirname(p)
    if under(p, PROD):
        if base.startswith(".env"):
            return "prod .env"
        if under(p, PROD + "/db-backups"):
            return "prod DB dump"
        if under(p, PROD + "/storage/app/private"):
            return "prod private uploads"
    if under(p, BSW + "/db-backups"):
        return "DB backup"
    if parent == BSW and re.search(r"\.(sql|sql\.gz|dump|tgz|tar|tar\.gz)$", base):
        return "DB dump / prod archive"
    if is_dev_env(p):
        return "dev .env (holds the prod-backup R2 keys)"
    return None


def is_dev_env(p):
    """.env* (not .env.example) at the root of the dev checkout or of a worktree under wt/."""
    base, parent = os.path.basename(p), os.path.dirname(p)
    return (PROTECT_DEV_ENV and base.startswith(".env") and base != ".env.example"
            and (parent == DEV or os.path.dirname(parent) == BSW + "/wt"))


def is_dev_env_home(p):
    """A place a dev .env may be copied/linked to: the dev or a worktree root, or a .env in one."""
    return p == DEV or os.path.dirname(p) == BSW + "/wt" or is_dev_env(p)


def is_secret_container(p):
    return any(under(d, p) for d in SECRET_DIRS)


def write_protected(p):
    if under(p, PROD):
        return "the prod checkout"
    if any(under(p, g) for g in GUARD_DIRS):
        return "Claude Code managed settings / host guard"
    parts = p.split("/")
    for i, part in enumerate(parts[:-1]):
        if part == ".claude":
            nxt = parts[i + 1]
            if nxt == "hooks":
                return "a Claude hook directory"
            if i + 1 == len(parts) - 1 and nxt.startswith("settings") and nxt.endswith(".json"):
                return "a Claude settings file"
    if p.startswith("/etc/sudoers") or any(under(p, s) for s in SYSTEM_WRITE_ROOTS):
        return "host system config"
    return None


def is_protected_ancestor(p):
    """p is a protected root or one of its ancestors (so rm -rf/mv/chmod -R on p reaches it)."""
    return any(under(r, p) for r in PROTECTED_ROOTS)


def glob_to_regex(pattern):
    """Shell-like glob → regex: `*`/`?` never cross `/` nor match a leading dot."""
    out = []
    for comp in pattern.split("/"):
        rx, i = "", 0
        if comp[:1] in ("*", "?"):
            rx += r"(?!\.)"
        while i < len(comp):
            c = comp[i]
            if c == "*":
                rx += "[^/]*"
            elif c == "?":
                rx += "[^/]"
            elif c == "[":
                j = comp.find("]", i + 1)
                if j == -1:
                    rx += r"\["
                else:
                    rx += "[" + comp[i + 1:j].replace("!", "^", 1) + "]"
                    i = j
            else:
                rx += re.escape(c)
            i += 1
        out.append(rx)
    try:
        return re.compile("^" + "/".join(out) + "$")
    except re.error:
        return re.compile("^" + re.escape(pattern) + "$")


# Canonical protected files: a glob that COULD expand to one of these is treated as if it did,
# so `cat /opt/bswebsaas/pro?/.e*` is caught even where the file does not exist (e.g. CI).
SAMPLES = [PROD + "/.env", PROD + "/.env.bak-x", PROD + "/db-backups/x.sql",
           PROD + "/storage/app/private/x", PROD + "/artisan", BSW + "/db-backups/x.sql.gz",
           BSW + "/x.sql", BSW + "/x.tgz", DEV + "/.env", DEV + "/.env.bak-x",
           "/etc/claude-code/managed-settings.json", "/etc/claude-code/hooks/host_guard.py"]


class Ctx:
    """Per-command state: effective cwd (tracks cd) and variables assigned in the command."""

    def __init__(self, cwd, cwd_unknown=False, variables=None):
        self.cwd = cwd
        self.cwd_unknown = cwd_unknown
        self.vars = dict(variables or {})

    def copy(self):
        return Ctx(self.cwd, self.cwd_unknown, self.vars)


def expand(tok, ctx):
    def sub(m):
        name = m.group(1) or m.group(2)
        if name == "HOME":
            return HOME
        if name == "PWD":
            return ctx.cwd
        return ctx.vars.get(name, m.group(0))
    t = re.sub(r"\$\{(\w+)\}|\$(\w+)", sub, tok)
    if t == "~" or t.startswith("~/"):
        t = HOME + t[1:]
    return t


def resolve(tok, ctx):
    """Every absolute path a token may denote: normalised, symlink-resolved, glob-expanded."""
    t = expand(tok, ctx)
    if not t or SUBST in t or "\n" in t:
        return set()
    if not t.startswith("/"):
        if ctx.cwd_unknown:
            return set()
        t = os.path.join(ctx.cwd, t)
    norm = os.path.normpath(t)
    out = {norm, os.path.realpath(norm)}
    if any(c in norm for c in "*?[") and not re.search(r"[\s|(){}\"'=,:]", t):
        rx = glob_to_regex(norm)
        out |= {s for s in SAMPLES if rx.match(s)}
        try:
            out |= {os.path.realpath(m) for m in globmod.glob(norm)[:500]}
        except (OSError, re.error):
            pass
    return out


# --------------------------------------------------------------------------- tokenising

def split_heredocs(text):
    """(text without heredoc bodies, [(body, kind)]); kind = shell | code | data."""
    lines, out, bodies, i = text.split("\n"), [], [], 0
    while i < len(lines):
        line = lines[i]
        out.append(line)
        i += 1
        for dash, _q, word in HEREDOC_RE.findall(line):
            body = []
            while i < len(lines):
                cur = lines[i]
                i += 1
                if (cur.lstrip("\t") if dash else cur) == word:
                    break
                body.append(cur)
            kind = "shell" if HEREDOC_SHELL_RE.search(line) else (
                "code" if HEREDOC_CODE_RE.search(line) else "data")
            bodies.append(("\n".join(body), kind))
    return "\n".join(out), bodies


def extract_substitutions(text):
    """Pull $(…), `…`, <(…), >(…) out (outside single quotes) → (text with placeholders, inners)."""
    out, inners, i, sq = [], [], 0, False
    while i < len(text):
        c = text[i]
        if c == "'":
            sq = not sq
        if not sq and text.startswith(("$(", "<(", ">("), i) and not text.startswith("$((", i):
            depth, j = 1, i + 2
            while j < len(text) and depth:
                depth += {"(": 1, ")": -1}.get(text[j], 0)
                j += 1
            inners.append(text[i + 2:j - 1])
            out.append(SUBST)
            i = j
            continue
        if not sq and c == "`" and not (i and text[i - 1] == "\\"):
            j = text.find("`", i + 1)
            j = len(text) if j == -1 else j
            inners.append(text[i + 1:j])
            out.append(SUBST)
            i = j + 1
            continue
        out.append(c)
        i += 1
    return "".join(out), inners


def unquoted_newlines_to_semicolons(text):
    out, q, esc = [], None, False
    for ch in text:
        if esc:
            out.append(ch)
            esc = False
        elif ch == "\\" and q != "'":
            out.append(ch)
            esc = True
        elif q:
            q = None if ch == q else q
            out.append(ch)
        elif ch in "'\"":
            q = ch
            out.append(ch)
        else:
            out.append(" ; " if ch == "\n" else ch)
    return "".join(out)


def split_ops(tok):
    res, i = [], 0
    while i < len(tok):
        for op in OPS:
            if tok.startswith(op, i):
                res.append(op)
                i += len(op)
                break
        else:
            res.append(tok[i])
            i += 1
    return res


def tokenize(text):
    text = text.replace("\\(", " __HG_LP__ ").replace("\\)", " __HG_RP__ ")
    raw = None
    for attempt in (text, text + '"', text + "'"):
        lex = shlex.shlex(attempt, posix=True, punctuation_chars=True)
        lex.whitespace_split = True
        lex.commenters = ""
        try:
            raw = list(lex)
            break
        except ValueError:
            continue
    if raw is None:
        raw = text.split()
    toks = []
    for t in raw:
        if t and all(ch in "();<>|&" for ch in t):
            toks.extend(split_ops(t))
        else:
            toks.append(t)
    return toks


def _open_find_exec(seg):
    opened = False
    for t in seg:
        if t in ("-exec", "-execdir", "-ok", "-okdir"):
            opened = True
        elif t in (";", "+"):
            opened = False
    return opened


def pipelines(tokens):
    """Split into pipelines of segments; a find -exec's `;` stays inside its segment.
    A subshell `( … )` shows up as the markers "PUSH"/"POP" so its cd does not leak."""
    lines, pipe, seg = [], [], []
    for t in tokens:
        if t == ";" and seg and _open_find_exec(seg):
            seg.append(t)
            continue
        if t in ("(", ")"):
            if seg:
                pipe.append(seg)
            seg = []
            if pipe:
                lines.append(pipe)
                pipe = []
            lines.append("PUSH" if t == "(" else "POP")
            continue
        if t in SEPARATORS:
            if seg:
                pipe.append(seg)
            seg = []
            if t not in PIPES and pipe:
                lines.append(pipe)
                pipe = []
            continue
        seg.append(t)
    if seg:
        pipe.append(seg)
    if pipe:
        lines.append(pipe)
    return lines


# --------------------------------------------------------------------------- wrappers

WRAPPER_OPTS_WITH_ARG = {
    "sudo": {"-u", "-g", "-h", "-p", "-C", "-T", "-r", "-t", "-U"},
    "doas": {"-u", "-C"},
    "timeout": {"-s", "-k", "--signal", "--kill-after"},
    "nice": {"-n", "--adjustment"},
    "ionice": {"-c", "-n", "-p", "--class", "--classdata"},
    "stdbuf": {"-i", "-o", "-e"},
    "xargs": {"-I", "-i", "-n", "-P", "-d", "-a", "-L", "-s", "-E", "-e", "--max-args",
              "--max-procs", "--delimiter", "--arg-file", "--max-lines", "--replace"},
    "flock": {"-w", "--timeout", "-E", "--conflict-exit-code"},
}
SIMPLE_WRAPPERS = {"nohup", "setsid", "time", "builtin", "unbuffer", "busybox", "then", "do",
                   "else", "elif", "if", "while", "until", "!", "{", "exec", "command"}
IGNORED_SEGMENTS = {"case", "select", "function", "done", "fi", "esac", "}", "in"}


def _strip_env(toks, ctx, assigns):
    i = 1
    while i < len(toks):
        t = toks[i]
        m = ASSIGN_RE.match(t)
        if m:
            assigns[m.group(1)] = expand(m.group(2), ctx)
            i += 1
        elif t in ("-C", "--chdir"):
            if i + 1 < len(toks):
                _cd(ctx, toks[i + 1])
            i += 2
        elif t.startswith("--chdir="):
            _cd(ctx, t.split("=", 1)[1])
            i += 1
        elif t in ("-u", "--unset", "-S", "--split-string"):
            i += 2
        elif t.startswith("-"):
            i += 1
        else:
            break
    return toks[i:]


def strip_wrappers(toks, ctx, assigns):
    """Drop leading assignments and wrapper commands → (tokens, came_through_xargs)."""
    via_xargs = False
    while toks:
        m = ASSIGN_RE.match(toks[0])
        if m:
            assigns[m.group(1)] = expand(m.group(2), ctx)
            toks = toks[1:]
            continue
        name = os.path.basename(toks[0])
        if name in SIMPLE_WRAPPERS:
            if name == "command" and len(toks) > 1 and toks[1] in ("-v", "-V"):
                return [], via_xargs
            toks = toks[1:]
            continue
        if name == "env":
            if len(toks) == 1:
                return toks, via_xargs
            toks = _strip_env(toks, ctx, assigns)
            continue
        if name not in WRAPPER_OPTS_WITH_ARG:
            break
        with_arg, i = WRAPPER_OPTS_WITH_ARG[name], 1
        while i < len(toks) and toks[i].startswith("-") and toks[i] != "--":
            flag = toks[i]
            if name == "flock" and flag in ("-c", "--command"):
                return ["sh", "-c"] + toks[i + 1:i + 2], via_xargs
            i += 2 if (flag in with_arg and "=" not in flag) else 1
        if i < len(toks) and toks[i] == "--":
            i += 1
        if name == "timeout":
            i += 1  # the duration
        if name == "flock":
            i += 1  # the lock file / fd
            if i < len(toks) and toks[i] in ("-c", "--command"):
                return ["sh", "-c"] + toks[i + 1:i + 2], via_xargs
        via_xargs = via_xargs or name == "xargs"
        toks = toks[i:]
    return toks, via_xargs


def _cd(ctx, target):
    t = expand(target, ctx)
    if SUBST in t or "$" in t:
        ctx.cwd_unknown = True
        return
    if t == "-":
        return
    ctx.cwd = os.path.normpath(t if t.startswith("/") else os.path.join(ctx.cwd, t))
    ctx.cwd_unknown = False


def split_redirects(toks):
    args, outs, ins, i = [], [], [], 0
    while i < len(toks):
        t = toks[i]
        if t in REDIR_OUT or t in ("<", ">&", "<&", "<<<", "<<"):
            nxt = toks[i + 1] if i + 1 < len(toks) else ""
            if t in REDIR_OUT and not re.fullmatch(r"\d+|-", nxt):
                outs.append(nxt)
            elif t == "<":
                ins.append(nxt)
            i += 2
            continue
        args.append(t)
        i += 1
    return args, outs, ins


def nonflag(args):
    return [a for a in args if not a.startswith("-") or a == "-"]


# --------------------------------------------------------------------------- Bash analysis

def analyze_text(text, ctx, depth=0):
    if depth > 6:
        raise Deny("nesting", "command nests too deeply to inspect")
    main, bodies = split_heredocs(text.replace("\\\n", " "))
    if PIPE_TO_SHELL_RE.search(main) or SHELL_SUBST_FETCH_RE.search(main):
        raise Deny("pkg-curl-sh", "downloading a script and piping it into a shell/interpreter")
    for body, kind in bodies:
        if kind == "shell":
            analyze_text(body, ctx, depth + 1)
        elif kind == "code":
            check_inline_code(body, ctx)
    main, inners = extract_substitutions(main)
    for inner in inners:
        analyze_text(inner, ctx.copy(), depth + 1)
    subst_secret = any(_mentions_secret_area(inner, ctx) for inner in inners)
    outer_flag, ctx.subst_secret = getattr(ctx, "subst_secret", False), subst_secret
    try:
        _analyze_pipelines(main, ctx, depth, subst_secret)
    finally:
        ctx.subst_secret = outer_flag


def _analyze_pipelines(main, ctx, depth, subst_secret):
    stack = []
    for pipe in pipelines(tokenize(unquoted_newlines_to_semicolons(main))):
        if pipe == "PUSH":
            stack.append((ctx.cwd, ctx.cwd_unknown))
            continue
        if pipe == "POP":
            if stack:
                ctx.cwd, ctx.cwd_unknown = stack.pop()
            continue
        earlier_secret = subst_secret
        for seg in pipe:
            info = analyze_segment(seg, ctx, depth)
            if info.get("via_xargs") and earlier_secret and info.get("reads_content"):
                raise Deny("secret-read", "feeding names from a secret area into xargs")
            earlier_secret = earlier_secret or info.get("secret_area", False)


PATH_EMITTERS = {"find", "ls", "echo", "printf", "realpath", "readlink", "locate", "fd", "grep", "rg"}


def _mentions_secret_area(text, ctx):
    toks = tokenize(text)
    if not toks or os.path.basename(toks[0]) not in PATH_EMITTERS:
        return False
    for tok in toks:
        for p in resolve(tok, ctx):
            if secret_kind(p) or is_secret_container(p):
                return True
    return False


def check_inline_code(code, ctx):
    """Inline interpreter code can do anything: refuse it when it names a protected place."""
    if PKG_IN_CODE_RE.search(code):
        raise Deny("pkg", "inline code runs a system package manager")
    writes = re.search(r"""open\([^)]*['"][wax+]|\.write|dump\(|unlink|remove|rename|rmtree|chmod|chown|"""
                       r"""system\(|subprocess|shutil\.(move|copy)|writeFile|file_put_contents|truncate""", code)
    if re.search(r"""(open|Path|read_text|load_dotenv|dotenv_values|readFileSync|file_get_contents|fopen)"""
                 r"""\s*\(\s*['"](\./)?\.env""", code):
        for p in resolve(".env", ctx):
            if secret_kind(p):
                raise Deny("secret-read", f"inline code opens {secret_kind(p)} ({p})")
    for lit in re.findall(r"""(?:^|[\s'"(=,])((?:/|~/|\.\.?/)[^\s'"(),;]+)""", code):
        if not lit.strip("/"):
            continue
        for p in resolve(lit, ctx):
            if secret_kind(p):
                raise Deny("secret-read", f"inline code references {secret_kind(p)} ({p})")
            if write_protected(p) and writes:
                raise Deny("protected-write",
                           f"inline code touches {write_protected(p) or 'a protected tree'} ({p})")
    if not ctx.cwd_unknown and under(ctx.cwd, PROD):
        raise Deny("prod-write", "running interpreter code inside the prod checkout")


def analyze_segment(seg, ctx, depth):
    assigns = {}
    if seg[0] == "for":
        _bind_for_variable(seg, ctx)
        return {}
    toks, via_xargs = strip_wrappers(list(seg), ctx, assigns)
    for k, v in assigns.items():
        ctx.vars[k] = v
        for p in resolve(v, ctx):
            if secret_kind(p):
                raise Deny("secret-read", f"variable {k} points at {secret_kind(p)} ({p})")
    info = {"via_xargs": via_xargs}
    if not toks:
        return info
    cmd = toks[0]
    name = command_name(cmd, ctx)
    info["reads_content"] = name not in META_ONLY | DATA_ARGS | {"find", "wc"}
    if name in IGNORED_SEGMENTS:
        return info
    if cmd.startswith("$") or SUBST in cmd:
        raise Deny("dynamic-command", f"command name comes from a variable/substitution ({cmd})")
    args, outs, ins = split_redirects(toks[1:])
    if SUBST in args and getattr(ctx, "subst_secret", False) and name not in META_ONLY | DATA_ARGS:
        raise Deny("secret-read", f"`{name}` is fed paths computed from a secret area")

    if name in SYSADMIN:
        raise Deny("sysadmin", f"`{name}` changes host administration (cron/services/users/firewall)")
    check_packages(name, cmd, args, ctx)
    if name in DEPLOY_SCRIPTS:
        raise Deny("deploy-script", "the prod deploy/rollback scripts are run by the owner via `!`")

    nested = None
    if name in SHELLS:
        for i, a in enumerate(args):
            if a.startswith("-") and not a.startswith("--") and "c" in a[1:]:
                nested = args[i + 1] if i + 1 < len(args) else ""
                break
        else:
            script = next((a for a in args if not a.startswith("-")), None)
            syntax_only = any(a.startswith("-") and not a.startswith("--") and "n" in a[1:] for a in args)
            if script and _is_deploy_script(script, ctx) and not syntax_only:
                raise Deny("deploy-script", "the prod deploy/rollback scripts are run by the owner via `!`")
    elif name == "eval":
        nested = " ".join(args)
    elif name == "su" and "-c" in args:
        nested = args[args.index("-c") + 1] if args.index("-c") + 1 < len(args) else ""
    elif name in ("ssh", "watch", "script"):
        nested = _nested_command(name, args)
    elif name in ("source", "."):
        if args and _is_deploy_script(args[0], ctx):
            raise Deny("deploy-script", "the prod deploy/rollback scripts are run by the owner via `!`")
    elif INTERP_RE.match(name):
        for flag in ("-c", "-e", "-r", "--eval", "-E"):
            if flag in args and args.index(flag) + 1 < len(args):
                check_inline_code(args[args.index(flag) + 1], ctx)
        script = next((a for a in args if not a.startswith("-")), None)
        if script and _is_deploy_script(script, ctx):
            raise Deny("deploy-script", "the prod deploy/rollback scripts are run by the owner via `!`")
    if nested is not None:
        analyze_text(nested, ctx.copy(), depth + 1)
        if name in SHELLS or name in ("eval", "su"):
            _check_redirects(outs, ins, ctx)
            return info

    if name in ("docker", "docker-compose"):
        check_docker(name, args, ctx, assigns, via_xargs)
    elif name == "git":
        check_git(args, ctx)
    elif name in ("cd", "pushd"):
        _cd(ctx, args[0] if args else HOME)
        return info
    elif name in DATA_ARGS:
        pass
    else:
        info["secret_area"] = check_paths(name, args, ctx)
    _check_redirects(outs, ins, ctx)
    return info


def command_name(cmd, ctx):
    """basename of the command, or of the real binary when a symlink/alias hides a watched one."""
    exp = expand(cmd, ctx)
    name = os.path.basename(exp)
    path = exp if "/" in exp else shutil.which(exp)
    if path:
        real = os.path.basename(os.path.realpath(path if path.startswith("/") else os.path.join(ctx.cwd, path)))
        if real != name and real in WATCHED:
            return real
    return name


def _is_deploy_script(tok, ctx):
    t = expand(tok, ctx)
    return os.path.basename(t) in DEPLOY_SCRIPTS or any(
        os.path.basename(p) in DEPLOY_SCRIPTS for p in resolve(t, ctx))


def _bind_for_variable(seg, ctx):
    """`for f in LIST` → bind f to the most dangerous item so `rm $f` in the body is judged."""
    if len(seg) >= 3 and seg[2] == "in":
        items = [t for t in seg[3:] if t not in (";", "do")]
        if items:
            pick = next((t for t in items if any(
                write_protected(p) or secret_kind(p) for p in resolve(t, ctx))), items[0])
            ctx.vars[seg[1]] = expand(pick, ctx)


def _check_redirects(outs, ins, ctx):
    for t in outs:
        for p in resolve(t, ctx):
            why = write_protected(p)
            if why:
                raise Deny("protected-write", f"redirecting output into {why} ({p})")
            if secret_kind(p):
                raise Deny("secret-read", f"redirecting output over {secret_kind(p)} ({p})")
    for t in ins:
        for p in resolve(t, ctx):
            if secret_kind(p):
                raise Deny("secret-read", f"redirecting {secret_kind(p)} into a command ({p})")


def _nested_command(name, args):
    if name == "script":
        k = args.index("-c") if "-c" in args else -1
        return args[k + 1] if 0 <= k < len(args) - 1 else ""
    if name == "watch":
        i = 0
        while i < len(args) and args[i].startswith("-"):
            i += 2 if args[i] in ("-n", "--interval") else 1
        return " ".join(args[i:])
    with_arg = set("bcDEeFIiJLlmOoPpQRSWw")  # ssh options that take a value
    i = 0
    while i < len(args) and args[i].startswith("-"):
        i += 2 if len(args[i]) == 2 and args[i][1] in with_arg else 1
    return " ".join(args[i + 1:])  # everything after the host


def check_packages(name, cmd, args, ctx):
    words = nonflag(args)
    sub = words[0] if words else ""
    if name in ("apt", "apt-get", "aptitude"):
        simulate = any(a in ("-s", "--simulate", "--dry-run", "--just-print", "--no-act", "--recon")
                       for a in args)
        read_only = (name == "apt" and sub in {"list", "show", "search", "policy", "depends",
                                               "rdepends", "showsrc"}) \
            or (name == "apt-get" and sub in {"check", "changelog"})
        if not (simulate or read_only):
            raise Deny("pkg", f"`{name} {sub}` installs/removes system software")
        return
    if name == "dpkg":
        ro = {"-l", "--list", "-L", "--listfiles", "-S", "--search", "-s", "--status", "-p",
              "--print-avail", "--get-selections", "--compare-versions", "--print-architecture",
              "--version", "-V", "--verify", "--audit", "-C"}
        if not (args and args[0] in ro):
            raise Deny("pkg", "`dpkg` installs/removes system software")
        return
    if name == "snap":
        if sub not in {"list", "info", "find", "version", "changes", "warnings", "services", "known"}:
            raise Deny("pkg", f"`snap {sub}` installs/removes system software")
        return
    if name in ("yum", "dnf", "apk", "zypper", "pacman", "rpm", "easy_install", "pipx"):
        raise Deny("pkg", f"`{name}` installs system software")
    in_venv = bool(re.search(r"(^|/)(\.?venv|\.virtualenvs)/", expand(cmd, ctx)))
    if re.fullmatch(r"pip\d*(\.\d+)?", name) and sub in ("install", "uninstall") and not in_venv:
        raise Deny("pkg", "`pip install` outside a project virtualenv (use .venv/bin/pip)")
    if name.startswith("python") and "-m" in args:
        k = args.index("-m")
        mod = args[k + 1] if k + 1 < len(args) else ""
        if mod in ("pip", "pip3") and nonflag(args[k + 2:])[:1] in (["install"], ["uninstall"]) and not in_venv:
            raise Deny("pkg", "`python -m pip install` outside a project virtualenv")
    if name == "uv" and (words[:2] == ["tool", "install"] or ("--system" in args and sub == "pip")):
        raise Deny("pkg", "`uv` global/system install")
    global_flag = any(a in ("-g", "--global", "--location=global") for a in args) or \
        ("--location" in args and "global" in args)
    if name == "npm" and global_flag and sub in {
            "install", "i", "in", "ins", "inst", "insta", "instal", "isnt", "isnta", "isntal",
            "isntall", "add", "uninstall", "un", "unlink", "remove", "rm", "r", "update", "up",
            "upgrade", "link", "ln", "ci"}:
        raise Deny("pkg", "`npm -g` installs system-wide")
    if name in ("pnpm", "bun") and global_flag:
        raise Deny("pkg", f"`{name} -g` installs system-wide")
    if name == "yarn" and words[:1] == ["global"]:
        raise Deny("pkg", "`yarn global` installs system-wide")


def check_docker(name, args, ctx, assigns, via_xargs):
    i, compose, mgmt = 0, name == "docker-compose", ""
    if not compose:
        while i < len(args) and args[i].startswith("-"):
            i += 2 if args[i] in ("--context", "-c", "--host", "-H", "--config", "--log-level", "-l") else 1
        mgmt = args[i] if i < len(args) else ""
        i += 1
        compose = mgmt == "compose"
    prod, project, files, projdir = False, None, [], None
    if compose:
        with_arg = {"-p", "--project-name", "-f", "--file", "--project-directory", "--env-file",
                    "--profile", "--ansi", "--progress", "--parallel"}
        while i < len(args) and args[i].startswith("-"):
            a = args[i]
            key, eq, val = a.partition("=")
            if key in with_arg:
                if not eq:
                    val = args[i + 1] if i + 1 < len(args) else ""
                    i += 1
                if key in ("-p", "--project-name"):
                    project = val
                elif key in ("-f", "--file"):
                    files.append(val)
                elif key == "--project-directory":
                    projdir = val
            elif a.startswith("-p") and len(a) > 2:
                project = a[2:]
            i += 1
        op, rest = (args[i] if i < len(args) else ""), args[i + 1:]
        project = project or assigns.get("COMPOSE_PROJECT_NAME") or ctx.vars.get("COMPOSE_PROJECT_NAME")
        cf = assigns.get("COMPOSE_FILE") or ctx.vars.get("COMPOSE_FILE")
        files += cf.split(":") if cf else []
        prod = bool(project and PROD_NAME_RE.search(project)) \
            or any(under(p, PROD) for f in files for p in resolve(f, ctx)) \
            or bool(projdir and any(under(p, PROD) for p in resolve(projdir, ctx))) \
            or (not project and not files and not projdir and not ctx.cwd_unknown and under(ctx.cwd, PROD))
        allowed = op in ("ps", "logs")
    else:
        op, rest = mgmt, args[i:]
        if mgmt in ("container", "volume", "network", "image", "system", "builder", "context"):
            op, rest = (args[i] if i < len(args) else ""), args[i + 1:]
        allowed = op in ("ps", "logs", "inspect", "ls")
    if any(PROD_NAME_RE.search(t) for t in args + list(assigns.values())):
        prod = True
    for src, dst in _mounts(rest):
        if PROD_NAME_RE.search(src):
            prod = True
        if src.startswith(("/", ".", "~")):
            for p in resolve(src, ctx):
                if is_dev_env(p) and not prod and dst == "/app/.env":
                    continue  # how a worktree's test container gets the dev database settings
                if under(p, PROD) or is_secret_container(p) or secret_kind(p) or is_protected_ancestor(p):
                    raise Deny("docker-prod", f"mounting {p} into a container exposes prod/host files")
    if op == "prune":
        raise Deny("docker-prod", f"`docker {mgmt} prune` can remove prod containers/volumes/networks")
    if op in DOCKER_MUTATING and (via_xargs or SUBST in rest):
        raise Deny("docker-prod", "bulk docker operation on a computed container list can hit prod")
    if prod:
        if not allowed:
            raise Deny("docker-prod", f"`docker {op}` on the prod project/containers (only ps, logs, inspect)")
        if op == "inspect" and mgmt not in ("volume", "network", "image"):
            fmt = _format_value(rest)
            if fmt is None or _format_leaks_env(fmt):
                raise Deny("docker-prod", "`docker inspect` on prod prints the container env (= .env "
                           "secrets); pass --format with the fields you need, e.g. '{{.State.Status}}'")
    env_files = [args[k + 1] for k, a in enumerate(args[:-1]) if a == "--env-file"] + \
        [a.split("=", 1)[1] for a in args if a.startswith("--env-file=")]
    for f in env_files:
        for p in resolve(f, ctx):
            if secret_kind(p) and (not is_dev_env(p) or prod):
                raise Deny("secret-read", f"passing {secret_kind(p)} into a container ({p})")
    if op in ("exec", "run") and not prod and _container_dumps_env(_container_command(rest)):
        raise Deny("secret-read", "printing a dev container's environment (it carries the dev .env secrets)")
    if PROTECT_DEV_ENV and op in ("exec", "run", "cp"):
        mount_values = {rest[k + 1] for k, a in enumerate(rest[:-1])
                        if a in ("-v", "--volume", "--mount", "--env-file")}
        for t in rest:
            if t in mount_values or t.startswith(("-v", "--volume=", "--mount=", "--env-file=")):
                continue
            if re.search(r"(^|[/:\s])\.env(\.bak[\w.-]*|\.local|\.production)?$", t):
                raise Deny("secret-read", "reading .env through a container")


CONTAINER_OPTS_WITH_VALUE = {
    "-v", "--volume", "-e", "--env", "--env-file", "--network", "--net", "-w", "--workdir", "--name",
    "-u", "--user", "-p", "--publish", "--mount", "--add-host", "-l", "--label", "-m", "--memory",
    "--cpus", "--platform", "--pull", "--restart", "--cap-add", "--cap-drop", "--device", "--dns",
    "--hostname", "-h", "--ipc", "--pid", "--log-driver", "--log-opt", "--shm-size", "--ulimit",
    "--security-opt", "--tmpfs", "--volumes-from", "--gpus", "--stop-signal", "--stop-timeout",
    "--cidfile", "--label-file", "--link", "--expose", "--index", "--detach-keys"}
ENV_DUMPERS = {"env", "printenv"}


def _container_command(rest):
    """Tokens of the command a docker/compose run|exec starts (after options and image/service)."""
    i, entry = 0, None
    while i < len(rest) and rest[i].startswith("-"):
        key = rest[i].split("=", 1)[0]
        if key == "--entrypoint":
            entry = rest[i].split("=", 1)[1] if "=" in rest[i] else (rest[i + 1] if i + 1 < len(rest) else "")
            i += 1 if "=" in rest[i] else 2
            continue
        i += 2 if key in CONTAINER_OPTS_WITH_VALUE and "=" not in rest[i] else 1
    cmd = rest[i + 1:]
    return ([entry] + cmd) if entry else cmd


def _container_dumps_env(cmd):
    """A dev container carries the dev .env in its environment: refuse the obvious ways to print it."""
    if not cmd:
        return False
    first, text = os.path.basename(cmd[0]), " ".join(cmd)
    secretish = re.compile(r"(?i)TOKEN|KEY|SECRET|PASS|PWD|CREDENTIAL|AUTH|DSN|^\$")
    if first in ENV_DUMPERS and (len(cmd) == 1 or first == "env" or any(secretish.search(a) for a in cmd[1:])):
        return True
    if re.search(r"/proc/\S*/environ", text):
        return True
    if first in SHELLS | {"ash"} and re.search(
            r"printenv|(^|[\s;&|])env(\s|$)|/proc/\S*/environ|\$\{?\w*(TOKEN|KEY|SECRET|PASSWORD)", text):
        return True
    return ("tinker" in cmd or (first.startswith("php") and "-r" in cmd)) and bool(
        re.search(r"\benv\(|getenv|\$_ENV|\$_SERVER", text))


def _mount_sources(rest):
    return [src for src, _dst in _mounts(rest)]


def _mounts(rest):
    """(source, target) of every -v/--volume/--mount/--volumes-from in a docker command."""
    srcs, i = [], 0
    while i < len(rest):
        a = rest[i]
        key, eq, val = a.partition("=")
        if key in ("-v", "--volume", "--mount", "--volumes-from"):
            if not eq:
                val = rest[i + 1] if i + 1 < len(rest) else ""
                i += 1
            if key == "--mount":
                m = re.search(r"(?:^|,)(?:source|src)=([^,]+)", val)
                t = re.search(r"(?:^|,)(?:target|dst|destination)=([^,]+)", val)
                srcs.append((m.group(1) if m else "", t.group(1) if t else ""))
            elif key != "--volumes-from":
                parts = val.split(":")
                srcs.append((parts[0], parts[1] if len(parts) > 1 else ""))
            else:
                srcs.append((val, ""))
        elif a.startswith("-v") and len(a) > 2:
            parts = a[2:].split(":")
            srcs.append((parts[0], parts[1] if len(parts) > 1 else ""))
        i += 1
    return srcs


def _format_value(rest):
    for i, a in enumerate(rest):
        if a in ("-f", "--format") and i + 1 < len(rest):
            return rest[i + 1]
        if a.startswith("--format="):
            return a.split("=", 1)[1]
    return None


def _format_leaks_env(fmt):
    return bool(re.search(r"(?i)env", fmt) or re.search(r"\.Config(?![.\w])", fmt)
                or re.search(r"\{\{-?\s*(json\s+|print\w*\s+)?\.\s*-?\}\}", fmt))


def check_git(args, ctx):
    gctx, i = ctx.copy(), 0
    while i < len(args) and args[i].startswith("-"):
        a = args[i]
        if a in ("-C", "--git-dir", "--work-tree") and i + 1 < len(args):
            _cd(gctx, args[i + 1])
            i += 2
            continue
        if a.startswith(("--git-dir=", "--work-tree=")):
            _cd(gctx, a.split("=", 1)[1])
        elif a == "-c":
            i += 1
        i += 1
    sub, rest = (args[i] if i < len(args) else ""), args[i + 1:]
    for t in ([] if sub in ("check-ignore", "ls-files", "status") else rest):
        for p in resolve(t, gctx):
            if secret_kind(p):
                raise Deny("secret-read", f"git would read {secret_kind(p)} ({p})")
    if gctx.cwd_unknown:
        if sub not in GIT_RO:
            raise Deny("prod-write", "git with an unresolvable -C/work-tree could target prod")
        return
    if under(gctx.cwd, PROD):
        words = nonflag(rest)
        risky = {"-d", "-D", "-m", "-M", "-f", "--delete", "--move", "--force", "-c", "-C"}
        read_only = sub in GIT_RO and not (
            (sub in ("branch", "tag") and (words or risky & set(rest)))
            or (sub == "remote" and words[:1] not in ([], ["show"], ["get-url"]))
            or (sub == "config" and not ({"--get", "--list", "-l", "--get-all", "--get-regexp"} & set(rest)))
            or (sub == "worktree" and words[:1] != ["list"])
            or (sub == "stash" and words[:1] not in (["list"], ["show"])))
        if not read_only:
            raise Deny("prod-write", f"`git {sub}` would change the prod checkout (read-only git only)")


def _drop_patterns(name, args):
    """The pattern/program argument of grep/sed/awk/jq is text, not a file to open."""
    if name not in PATTERN_FIRST:
        return args
    if name in ("grep", "egrep", "fgrep", "zgrep", "rg", "ag", "ack", "sed") and any(
            a in ("-e", "--regexp", "--expression") or a.startswith(("--regexp=", "--expression="))
            for a in args):
        out, skip = [], False
        for a in args:
            if skip:
                skip = False
                continue
            if a in ("-e", "--regexp", "--expression"):
                skip = True
                continue
            if not a.startswith(("--regexp=", "--expression=")):
                out.append(a)
        return out
    k = next((j for j, a in enumerate(args) if not a.startswith("-")), None)
    return args if k is None else args[:k] + args[k + 1:]


def _transfer_reads(name, args):
    """Local files curl/wget would send somewhere (the data-exfiltration path)."""
    out = []
    for i, a in enumerate(args):
        nxt = args[i + 1] if i + 1 < len(args) else ""
        if a in ("-T", "--upload-file", "--post-file", "--body-file"):
            out.append(nxt)
        elif a in ("-d", "--data", "--data-binary", "--data-raw", "--data-urlencode", "-F", "--form"):
            m = re.search(r"@([^;,]+)", nxt)
            if m:
                out.append(m.group(1))
        elif a.startswith(("--post-file=", "--body-file=", "--upload-file=")):
            out.append(a.split("=", 1)[1])
        elif re.match(r"^(-d|--data[\w-]*=|-F|--form=)@", a):
            out.append(a.split("@", 1)[1])
    return out


def check_paths(name, args, ctx):
    """Path rules for every other command. Returns True when it touches a secret area."""
    if name in META_ONLY or name == "find":
        return _check_find_or_meta(name, args, ctx)
    if name in RO_CMDS:
        writes = _ro_write_targets(name, args, ctx)
        if name in ("curl", "wget"):
            reads, recursive = _transfer_reads(name, args), False
        else:
            reads = [a for a in _drop_patterns(name, args) if a not in writes]
            recursive = name in RECURSIVE_READERS or (name in ("grep", "egrep", "fgrep", "zgrep") and any(
                a in ("-r", "-R", "--recursive", "--dereference-recursive")
                or (re.fullmatch(r"-[A-Za-z]+", a) and ("r" in a or "R" in a)) for a in args))
        _check_reads(name, reads, ctx, recursive)
        _check_writes(name, writes, ctx)
        return any(is_secret_container(p) for a in reads for p in resolve(a, ctx))
    if name in COPY_LIKE:
        srcs, dests, recursive = _copy_roles(name, args)
        if _is_dev_env_transfer(name, srcs, dests, ctx):
            return False  # moving the dev .env into a worktree: nothing is disclosed
        _check_reads(name, srcs, ctx, recursive)
        if name == "ln":
            for a in srcs:
                for p in resolve(a, ctx):
                    if write_protected(p) or secret_kind(p) or is_protected_ancestor(p):
                        raise Deny("protected-write", f"creating a symlink to a protected place ({p})")
                    if os.path.basename(p) in (SYSADMIN | PKG_NAMES | DEPLOY_SCRIPTS):
                        raise Deny("pkg", f"creating an alias for `{os.path.basename(p)}` hides it from the guard")
        _check_writes(name, dests, ctx)
        if not ctx.cwd_unknown and under(ctx.cwd, PROD):
            raise Deny("prod-write", f"`{name}` run inside the prod checkout")
        return False
    # Anything else may write: refuse when it names a protected or secret path.
    if name in ("rm", "unlink") and all(is_dev_env(p) for a in nonflag(args) for p in resolve(a, ctx)) and nonflag(args):
        return False
    _check_reads(name, args, ctx, recursive=False)
    _check_writes(name, args, ctx)
    if not ctx.cwd_unknown and under(ctx.cwd, PROD):
        raise Deny("prod-write", f"`{name}` run inside the prod checkout (only read-only commands there)")
    if ctx.cwd_unknown and any(not a.startswith(("/", "-")) for a in nonflag(args)):
        raise Deny("prod-write", f"`{name}` with relative paths after a cd the guard cannot resolve")
    return False


def _is_dev_env_transfer(name, srcs, dests, ctx):
    if name not in ("cp", "ln", "install", "rsync") or not srcs or not dests:
        return False
    src_paths = [p for a in srcs for p in resolve(a, ctx)]
    dst_paths = [p for a in dests for p in resolve(a, ctx)]
    flags = [a for a in srcs if a.startswith("-")]
    return (not flags and src_paths and all(is_dev_env(p) for p in src_paths)
            and dst_paths and all(is_dev_env_home(p) for p in dst_paths))


def _check_find_or_meta(name, args, ctx):
    secret_area = False
    actions = {"-delete", "-exec", "-execdir", "-ok", "-okdir", "-fprint", "-fprint0", "-fprintf", "-fls"}
    if name == "find" and actions & set(args):
        exec_toks = _find_exec_tokens(args)
        if exec_toks:
            analyze_segment(exec_toks, ctx.copy(), 1)
        first_flag = next((k for k, a in enumerate(args) if a.startswith("-")), len(args))
        for r in args[:first_flag] or ["."]:
            for p in resolve(r, ctx):
                if write_protected(p) or is_protected_ancestor(p) or is_secret_container(p) or secret_kind(p):
                    raise Deny("protected-write", f"find with -exec/-delete over a protected tree ({p})")
    pattern_flags = {"-name", "-iname", "-path", "-ipath", "-regex", "-iregex", "-wholename",
                     "-iwholename", "-lname", "-ilname", "-printf", "-fprintf"}
    for k, a in enumerate(args):
        if k and args[k - 1] in pattern_flags:
            continue
        for p in resolve(a, ctx):
            if is_secret_container(p) or secret_kind(p):
                secret_area = True
    return secret_area


def _find_exec_tokens(args):
    for k, a in enumerate(args):
        if a in ("-exec", "-execdir", "-ok", "-okdir"):
            end = next((j for j in range(k + 1, len(args)) if args[j] in (";", "+")), len(args))
            return args[k + 1:end]
    return []


def _ro_write_targets(name, args, ctx):
    out = []
    files = [a for a in _drop_patterns(name, args) if not a.startswith("-")]
    if name == "sed" and any(a.startswith(("-i", "--in-place")) for a in args):
        out = files
    elif name in ("awk", "gawk", "mawk"):
        prog = next((a for a in args if not a.startswith("-")), "")
        if "inplace" in " ".join(args) or "system(" in prog or re.search(r">\s*\"", prog):
            check_inline_code(prog, ctx)
            out = files
    for i, a in enumerate(args):
        nxt = args[i + 1] if i + 1 < len(args) else None
        if name == "sort" and a in ("-o", "--output") and nxt:
            out.append(nxt)
        elif name == "sort" and a.startswith("--output="):
            out.append(a.split("=", 1)[1])
        elif name == "curl" and a in ("-o", "--output") and nxt:
            out.append(nxt)
        elif name == "curl" and a in ("-O", "--remote-name", "--remote-name-all"):
            out.append(".")
        elif name == "wget" and a in ("-O", "--output-document", "-P", "--directory-prefix") and nxt:
            out.append(nxt)
    return out


def _copy_roles(name, args):
    """→ (sources read, destinations written, recursive)."""
    words = nonflag(args)
    recursive = any(a in ("-r", "-R", "-a", "--recursive", "--archive")
                    or (re.fullmatch(r"-[A-Za-z]+", a) and any(c in a for c in "rRa")) for a in args)
    if name == "tar":
        mode = args[0] if args else ""
        cluster = mode.lstrip("-") if re.fullmatch(r"-?[A-Za-z]+", mode) else ""
        create = "--create" in args or "c" in cluster
        archive = None
        if "f" in cluster and len(args) > 1:
            archive = args[1]
        for k, a in enumerate(args):
            if a in ("-f", "--file") and k + 1 < len(args):
                archive = args[k + 1]
        cdir = next((args[k + 1] for k, a in enumerate(args) if a in ("-C", "--directory") and k + 1 < len(args)), None)
        paths = [w for w in words if w not in (mode, archive, cdir)]
        if create:
            return paths, [archive] if archive else [], True
        return [archive] if archive else [], [cdir or "."], False
    if name in ("zip", "7z", "7za"):
        return words[1:], words[:1], True
    if name == "unzip":
        d = next((args[k + 1] for k, a in enumerate(args) if a == "-d" and k + 1 < len(args)), ".")
        return words[:1], [d], False
    t = next((args[k + 1] for k, a in enumerate(args) if a in ("-t", "--target-directory") and k + 1 < len(args)), None)
    if t:
        return [w for w in words if w != t], [t], recursive or name == "rsync"
    if len(words) < 2:
        return words, [], recursive
    return words[:-1], words[-1:], recursive or name == "rsync"


def _check_reads(name, toks, ctx, recursive):
    for a in toks:
        if ctx.cwd_unknown and not a.startswith("/") and os.path.basename(a).startswith(".env"):
            raise Deny("secret-read", f"`{name} {a}` after a cd the guard cannot resolve")
        for p in resolve(a, ctx):
            kind = secret_kind(p)
            if kind:
                raise Deny("secret-read", f"`{name}` would read {kind} ({p})")
            if recursive and is_secret_container(p):
                raise Deny("secret-read", f"recursive `{name}` over {p} would sweep up prod secrets")


def _check_writes(name, toks, ctx):
    for a in toks:
        for p in resolve(a, ctx):
            why = write_protected(p)
            if why:
                raise Deny("protected-write", f"`{name}` would change {why} ({p})")
            if name in DESTRUCTIVE and is_protected_ancestor(p):
                raise Deny("protected-write", f"`{name}` on {p} would reach a protected tree")
            if secret_kind(p):
                raise Deny("secret-read", f"`{name}` touches {secret_kind(p)} ({p})")


# --------------------------------------------------------------------------- non-Bash tools

def check_tool(tool, ti, cwd):
    ctx = Ctx(cwd)
    if tool == "Bash":
        analyze_text(ti.get("command") or "", ctx)
    elif tool == "Read":
        for p in resolve(ti.get("file_path") or "", ctx):
            if secret_kind(p):
                raise Deny("secret-read", f"Read of {secret_kind(p)} ({p})")
    elif tool in ("Edit", "Write", "MultiEdit", "NotebookEdit"):
        for p in resolve(ti.get("file_path") or ti.get("notebook_path") or "", ctx):
            why = write_protected(p) or (secret_kind(p) and "a secret file")
            if why:
                raise Deny("protected-write", f"{tool} of {why} ({p})")
    elif tool in ("Glob", "Grep"):
        _check_search(tool, ti, ctx)


def _check_search(tool, ti, ctx):
    pattern = (ti.get("pattern") if tool == "Glob" else ti.get("glob")) or ""
    for p in resolve(ti.get("path") or ctx.cwd, ctx):
        if secret_kind(p):
            raise Deny("secret-read", f"{tool} inside {secret_kind(p)} ({p})")
        if tool == "Grep" and is_secret_container(p):
            raise Deny("secret-read", f"Grep over {p} would read prod secrets; search the dev checkout")
        if pattern and (is_secret_container(p) or (PROTECT_DEV_ENV and p == DEV)):
            if re.search(r"\.env|\.sql|\.tgz|\.dump|db-backups|private", pattern):
                raise Deny("secret-read", f"{tool} pattern {pattern!r} under {p} targets secret files")
            rx = glob_to_regex(os.path.join(p, pattern.replace("**/", "")))
            hit = next((s for s in SAMPLES if secret_kind(s) and under(s, p) and rx.match(s)), None)
            if hit:
                raise Deny("secret-read", f"{tool} pattern {pattern!r} under {p} matches {secret_kind(hit)}")


def main():
    try:
        data = json.loads(sys.stdin.read() or "{}")
        check_tool(data.get("tool_name", ""), data.get("tool_input") or {},
                   data.get("cwd") or os.getcwd())
    except Deny as d:
        sys.stderr.write(
            f"host-guard BLOCKED [{d.rule}]: {d.why}.\n"
            "This host runs dev and prod together; the owner does this kind of operation via `!` "
            "(docs/runbooks/claude-code-host-guard.md).\n")
        sys.exit(2)
    except Exception as e:  # noqa: BLE001 — any bug must deny, never let the call through
        sys.stderr.write(f"host-guard internal error ({type(e).__name__}: {e}) — denied (fail-closed).\n")
        sys.exit(2)
    sys.exit(0)


if __name__ == "__main__":
    main()
