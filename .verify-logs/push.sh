#!/bin/bash
cd /home/rubenlinde/memcap-work/lq-lanes/lq-defects || exit 9
b=$(git branch --show-current)
git push -q origin "$b" 2>&1 | grep -v '^remote:'
r=$(git ls-remote origin refs/heads/$b | cut -f1); l=$(git rev-parse HEAD)
[ "$r" = "$l" ] && echo "pushed $b $l" || { echo "MISMATCH $r $l"; exit 2; }
