---
name: visual-design-consistency
description: Apply when the user asks to design, redesign, or visually implement an application screen. Extend the app's accepted visual language while preserving the screen's existing data and behavior.
---

# Visual Design Consistency

Use this skill for interface design work in this application.

## Carry the visual language forward

- Before proposing or changing a screen, inspect the current application and its existing styles, shared components, and design tokens.
- Treat the most recent screen the user has explicitly accepted as the visual reference for later screens. Keep its palette, typography, spacing rhythm, surface depth, shape language, and navigation style coherent across the app.
- Extend the existing system with restrained accents. Keep the user's specified brand colors and their intended roles; avoid adding colors or decorative styles without a clear interface purpose.
- If no screen has been accepted yet, use the existing application as the baseline and make the new screen feel like it belongs beside it.
- Use the app itself and its source as the reference. Use an external builder preview only when the user asks to inspect or compare it.

## Keep navigation present

- Keep the primary side menu visible on every application screen, including narrow layouts. When space is limited, keep an icon rail with accessible labels rather than hiding the menu.
- Use the shared application layout for screens outside the dashboard; keep the dashboard navigation consistent with that shared menu.
- Mark the current destination clearly and preserve the existing route and interaction for every menu item.

## Sequence multi-screen work with agents

- For a redesign spanning multiple screens, use three sequential agent roles: a visual agent implements the screen treatment; a compatibility agent compares the result with the existing screen and verifies that routes, data, calculations, state, forms, and backend contracts stay intact; an integration agent then applies the reviewed screens to the application.
- Pass each stage's concrete diff and findings to the next agent, and wait for its review before advancing. Keep file ownership separate when agents work in the same checkout.

## Keep design separate from behavior

- For a design-only request, create and show a visual proposal without editing application code.
- For an implementation request, limit changes to the requested screen's presentation. Preserve its existing routes, data sources, calculations, state, event handlers, validation, controls, copy, and interaction outcomes.
- Do not invent data or add functionality as part of a visual redesign. When a design needs information the screen does not provide, present the existing information in a clearer way and describe any remaining design choice.
- Do not redesign unrelated screens or shared flows unless the user requests that scope.
- If a visual treatment appears to require changing behavior, keep the current behavior and surface the design choice for the user.

## Complete the requested design work

- Match the proposal to the real screen dimensions and available content; account for narrow layouts when the app supports them.
- Use real screen data in an implementation. Label fictional values clearly in a visual-only mockup.
- After implementation, open the requested screen so the user can review the visual result. Summarize the visual changes and any behavior that was intentionally preserved.
