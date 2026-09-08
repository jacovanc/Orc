# Operator access onboarding checklist

Complete this checklist before allowing a new Orc administrator to start
Amp-backed workflows.

## Account controls

- [ ] Keep production self-registration disabled. Provision each account as an
  operator action through the approved administrative process.
- [ ] Grant `users.can_trigger_amp=true` to the exact existing account through
  an operator-controlled database/admin process; do not derive it from email.
- [ ] Confirm each owner-scoped Orc Project has the intended canonical
  repository and verified versioned Amp controller connection.

Registration and launch permission are independent. New accounts always default
to `can_trigger_amp=false`, and changing a profile email cannot change launch
authority. A permitted account can launch only through a Project it owns and a
connection whose fresh child proved the exact Amp project identity and native
read access to its canonical repository.

## Repository credentials

- [ ] Configure native Orb `git` and `gh` authentication for the user according
  to the operator's credential-management policy.
- [ ] Confirm the authenticated user has the intended repository access.

Repository access comes from this user-configured native Orb authentication.
Orc never provisions, copies, or displays GitHub credentials.

## Safe verification

Run checks inside the target production environment. Report only booleans,
non-secret IDs, and status; never print encrypted controller values:

```sh
php artisan tinker --execute="dump(
  App\\Models\\User::where('email', 'operator@example.com')->value('can_trigger_amp'),
  App\\Models\\Project::with('currentConnection:id,project_id,public_id,status,verified_at')->get(['id','user_id','github_repository','current_amp_project_connection_id'])
);"
```

Confirm native authentication without displaying credential material:

```sh
if git config --get credential.helper >/dev/null 2>&1; then
  echo 'git credential helper: configured'
else
  echo 'git credential helper: not configured'
fi

if gh auth status >/dev/null 2>&1; then
  echo 'gh authentication: configured'
else
  echo 'gh authentication: not configured'
fi
```

Finally, run **Verify in fresh Orb** for the Project. Confirm the returned thread
belongs to the intended Amp project and native `gh` can read the canonical
repository. Confirm another account receives 404 for the Project, and an account
without `can_trigger_amp` cannot configure, verify, start, retry, or request
changes into a new agent stage. Record no token, credential output, webhook URL,
or directional secret.
