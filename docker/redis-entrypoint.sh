#!/bin/sh
set -eu

source_acl=/run/secrets/redis_acl
runtime_acl=/tmp/zapcore-redis-users.acl

if [ ! -f "$source_acl" ] || [ ! -r "$source_acl" ] || [ ! -s "$source_acl" ]; then
  echo 'Redis ACL secret is missing, empty, or unreadable.' >&2
  exit 1
fi

install -o redis -g redis -m 0400 "$source_acl" "$runtime_acl"
exec su-exec redis redis-server --aclfile "$runtime_acl"
