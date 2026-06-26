# Ladna Brandbook Reference

## Role

Ladna is a SaaS product for sports studios, trainers, dance/yoga/fitness classes, schedules, bookings, class passes, customer flows, and studio operations.

Brand-facing work should feel:

- sporty and active
- warm and organized
- premium but approachable
- useful for both studio owners and athletes
- less corporate than generic back-office software

## Core Assets

Primary product mark:

- `public/brand/ladna-mark.svg`

Selected mascot concept reference:

- Public reference file: `public/assets/brand/mascot/concepts/ladna-mascot-concept-02-sporty-skirt.png`
- Skill bundled copy: `.agents/skills/ladna-brand/assets/mascot/concepts/ladna-mascot-concept-02-sporty-skirt.png`

Current production mascot cutout:

- Public mascot cutout: `public/assets/brand/mascot/ladna-mascot-sporty-cutout.png`
- Landing alias: `public/assets/brand/landing/ladna-landing-mascot-cutout.png`
- Skill bundled cutout: `.agents/skills/ladna-brand/assets/mascot/ladna-mascot-sporty-cutout.png`

The selected mascot is concept 02 from the first mascot exploration. It defines an image direction: an anime-inspired adult sporty studio assistant in a skort, lavender hoodie, cream top, deep plum details, sneakers, tablet, and water bottle.

Use this direction as the current mascot baseline, not as a finished character asset.

Do not use the saved PNG directly in production pages or landing sections. It includes exploratory background graphics, UI-like blocks, and circular emotion/gesture concepts. For real product surfaces, generate or design a new fit-for-purpose illustration that follows the same image direction.

When a page uses the mascot directly, use a transparent PNG/WebP/SVG-style cutout. Build the decorative background as part of the page or section design, not inside the mascot file. Avoid placing a rectangular generated illustration panel into a hero section unless the user explicitly asks for a poster/card treatment.

## Palette

Use the existing Ladna palette from `resources/css/app.css` and `public/brand/ladna-mark.svg`.

Core colors:

- Deep plum: `#3B223F`
- Dark plum: `#2B1731`
- Lavender: `#A78AB9`
- Soft lavender: `#C7B4D3`
- Pale lavender UI accent: `#DCCFF0`
- Warm cream: `#FAF8F5`
- Warm sand: `#E7DDC9`
- Ink: `#2B2B2F`

Supporting UI colors such as emerald, amber, and rose may be used for statuses, but they should not become the dominant landing palette.

## Mascot Direction

Use the mascot concept as a visual reference for the image direction, not as a fixed canonical character drawing. The concept captures the intended vibe, outfit family, color balance, and role; future visuals should be generated or illustrated to fit the exact context.

Use the mascot as a sporty SaaS guide, not as an office secretary.

Preferred traits:

- clearly adult young woman
- friendly, confident, active
- anime-inspired but original
- athletic-casual outfit
- skort or sport skirt over shorts
- lavender hoodie or warm-up layer
- cream top and deep plum accents
- modern sneakers
- tablet with abstract schedule/class-pass blocks
- optional water bottle or gym towel
- optional generated emotion/gesture variants when a specific page needs them

Avoid:

- schoolgirl look
- chibi or childlike proportions
- office suit as the default outfit
- low-cut top as a defining feature
- pin-up framing
- bright neon sportswear
- cosplay or fantasy props
- readable fake UI text

## Concept Image Caveats

The saved concept image is useful for:

- art direction
- image-generation references
- mood, outfit, and palette alignment
- planning mascot expression/gesture variants
- briefing a designer or future image-generation pass

The saved concept image is not suitable as direct final art because:

- it has a full decorative background
- it contains circular exploration callouts for emotions and poses
- it is not a clean transparent cutout
- it was generated as a concept board, not a final mascot pack
- it may need context-specific pose, crop, background, and polish for each landing section

When building a landing page, create a new asset for the section: hero mascot, dashboard companion, onboarding helper, empty-state illustration, testimonial accent, or footer/support character. Match the concept's image direction, but do not reuse the concept board unchanged.

For hero work, prefer this composition model:

- page-level background: warm cream, lavender arcs, rhythm blocks, soft studio silhouettes
- mascot layer: transparent cutout placed over the background
- UI/copy layer: native HTML text and controls

Do not bake the background into the mascot PNG when the mascot should visually live inside the page.

## Landing Page Guidance

For a Ladna landing page, show the actual product category immediately. The first viewport should communicate "SaaS for sports studios" without relying only on nav text.

Good first-viewport signals:

- Ladna mark or wordmark
- mascot or product UI
- schedule, booking, class-pass, trainer, and customer cues
- fitness/dance/yoga/studio context
- clear offer text about running a studio, selling passes, and managing bookings

Avoid making the landing feel like:

- a generic admin dashboard
- a generic wellness blog
- a startup template with no sports-studio context
- a decorative hero with no product signal

## Image Generation Prompt Seed

Use this as the baseline when generating mascot variants:

```text
Create an original anime-inspired adult woman mascot for Ladna, a SaaS product for sports studios, trainers, dance/yoga/fitness classes, schedules, bookings, and class passes.

She is a friendly young adult in her mid-20s, clearly adult, confident, active, and approachable for athletes and studio owners. She has deep plum hair with a soft lavender accent strand, warm expressive eyes, and a natural smile.

Outfit: tasteful athletic-casual skort or short A-line sport skirt over fitted shorts, cream fitted top, soft lavender cropped zip hoodie or warm-up jacket, deep plum details, and modern sneakers. She carries a slim tablet with abstract schedule blocks and class-pass cards. Add a small water bottle or gym towel as a sport cue.

Style: polished original anime-inspired character design, clean professional SaaS mascot, soft cel shading, premium but friendly.

Palette: deep plum #3B223F and #2B1731, lavender #A78AB9 and #C7B4D3, warm cream #FAF8F5 and #E7DDC9, ink #2B2B2F.

Constraints: no text, no logo lettering, no readable UI labels, no watermark. Keep her adult, non-sexualized, brand-safe, and sporty. Avoid chibi style, schoolgirl look, cosplay, fantasy props, busy background, and neon colors.
```
