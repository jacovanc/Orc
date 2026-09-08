# Operator access onboarding checklist

Complete this checklist before allowing a new Orc administrator to start
Amp-backed workflows.

## Account controls

- [ ] Keep production self-registration disabled. Provision each account as an
  operator action through the approved administrative process.
- [ ] Confirm the administrator's email is included in
  `AMP_ALLOWED_USER_EMAILS`.
- [ ] Confirm every repository the administrator may use is included in
  `AMP_ALLOWED_REPOSITORIES`.

The two allowlists are independent requirements. An account can trigger an Amp
launch only when its email passes `AMP_ALLOWED_USER_EMAILS` **and** the target
repository passes `AMP_ALLOWED_REPOSITORIES`. Membership in one allowlist does
not bypass the other.

## Repository credentials

- [ ] Configure native Orb `git` and `gh` authentication for the user according
  to the operator's credential-management policy.
- [ ] Confirm the authenticated user has the intended repository access.

Repository access comes from this user-configured native Orb authentication.
Orc never provisions, copies, or displays GitHub credentials.

## Safe verification

Run checks inside the target production environment or Orb. Report only the
configuration name and whether it is non-empty; never print, paste, or log the
configuration value:

```sh
for name in AMP_ALLOWED_USER_EMAILS AMP_ALLOWED_REPOSITORIES; do
  if [ -n "$(printenv "$name")" ]; then
    printf '%s: non-empty\n' "$name"
  else
    printf '%s: empty or unset\n' "$name"
  fi
done
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

Finally, verify with a non-destructive access check against one explicitly
allowed repository and one repository that is not allowed. Confirm that the
allowed combination can start the workflow and that changing either the user
email or repository to a non-allowed value blocks launch. Record only pass/fail
status; do not record allowlist contents, tokens, credential output, or other
secret values.
