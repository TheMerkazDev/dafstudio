# TextLab import model

The import staging object contains five top-level fields:

- `project_title`
- `project_description`
- `segments`
- `annotations`
- `outline`
- `warnings`

A segment contains a canonical `sefaria_ref`, a project-unique `segment_key`, explicit order, optional user-selected Hebrew/source span, the user translation, status, and metadata.

Annotations retain range, type, title, body, tags, output targets, and order. Outline nodes retain level, title, range, and order. Numbering is generated later and therefore should never be embedded into the imported title merely to preserve formatting.

AI-assisted import is intended for structural recovery, not content generation. It must never fill a missing translation or commentary entry with model-authored prose.
