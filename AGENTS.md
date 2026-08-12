# saddle

<!-- BEGIN @plugpress/ui (managed by fleet:agents) -->
## @plugpress/ui

This plugin's admin UI is built on the PlugPress design system. Before building or editing any
admin UI, read the usage guide shipped with the package:

    node_modules/@plugpress/ui/docs/consumer-agent-guide.md

It covers setup, the design rules you must follow, the component inventory, and why UI changes
don't appear until the pinned tag is bumped and the plugin is rebuilt.
<!-- END @plugpress/ui -->

## Before you start

Read `CLAUDE.md` in this repo before starting any task. It defines what Saddle is,
the three non-negotiables, the hard line on code execution and filesystem writes,
the architecture map, the WordPress plugin and testing rules, the required GitHub
workflow — issue → branch → commit → **push** → PR → CI → merge — the definition of
done, and the current direction. Those rules apply to every agent working here, not
just Claude.

Then read `STATUS.md` for where the last session left off.
