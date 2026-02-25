-- Migration 029: Update certificate_templates to use new diploma background designs
-- Date: 2026-02-24
-- Description: Points template_image to new diploma background PNGs for PDF generation
--              and thumbnail_image to background SVGs for gallery display

UPDATE certificate_templates SET
    template_image = '../diplomas/templates/backgrounds/template-1.png',
    thumbnail_image = '../diplomas/templates/backgrounds/template-1.svg'
WHERE id = 1;

UPDATE certificate_templates SET
    template_image = '../diplomas/templates/backgrounds/template-2.png',
    thumbnail_image = '../diplomas/templates/backgrounds/template-2.svg'
WHERE id = 2;

UPDATE certificate_templates SET
    template_image = '../diplomas/templates/backgrounds/template-3.png',
    thumbnail_image = '../diplomas/templates/backgrounds/template-3.svg'
WHERE id = 3;

UPDATE certificate_templates SET
    template_image = '../diplomas/templates/backgrounds/template-4.png',
    thumbnail_image = '../diplomas/templates/backgrounds/template-4.svg'
WHERE id = 4;

UPDATE certificate_templates SET
    template_image = '../diplomas/templates/backgrounds/template-5.png',
    thumbnail_image = '../diplomas/templates/backgrounds/template-5.svg'
WHERE id = 5;

UPDATE certificate_templates SET
    template_image = '../diplomas/templates/backgrounds/template-6.png',
    thumbnail_image = '../diplomas/templates/backgrounds/template-6.svg'
WHERE id = 6;
