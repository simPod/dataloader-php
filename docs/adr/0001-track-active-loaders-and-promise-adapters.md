# Track Active Loaders and Promise Adapters

## Status

Accepted

## Context

`DataLoader::await()` must dispatch queued loaders and use the promise adapter
that owns the supplied promise. A strong global registry kept every loader and
its cache alive. Selecting the first registered loader also sent promises to
the wrong adapter when an application used more than one promise library.

## Decision

Keep weak references to live loader instances. Retain a loader strongly only
while it has queued work, and release it when dispatch starts. Keep a weak map
from load promises to their owning adapters. For promises without recorded
ownership, ask live adapters whether they recognize the promise before using a
compatibility fallback.

A loader destructor cancels only that loader's queued promises. It does not
dispatch global work.

## Consequences

Idle and dispatched loaders can be garbage-collected. Inline temporary loaders
remain alive until their queued work is dispatched. Mixed promise libraries use
the correct adapter when ownership is known or recognized. A loader with queued
work remains retained until the application dispatches or cancels that work.
