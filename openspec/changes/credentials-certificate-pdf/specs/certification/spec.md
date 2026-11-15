## ADDED Requirements

### Requirement: A course names the template its certificates are printed on

A `Course` MUST be able to carry a `certificateTemplateSlug` that names a filinq document template. The course edit form MUST offer it as "Certificaatsjabloon". The unused `certificateTemplate` file path MUST be removed from the schema.

#### Scenario: A teacher sets the template

- **GIVEN** a course without a certificate template
- **WHEN** a teacher sets "Certificaatsjabloon" to `vca-basis` and saves
- **THEN** the course stores `certificateTemplateSlug: vca-basis`

### Requirement: Issuing a certificate renders its PDF, fail soft

When a `Credential` is issued for a course with a `certificateTemplateSlug`, the system MUST ask filinq's render endpoint for a PDF with the learner's name, the course name, the issue date, the expiry date, the validity label and the verification URL. On success it MUST store `pdfDocumentRef` and set `pdfRenderStatus` to `rendered`. On any failure it MUST set `pdfRenderStatus` to `failed` or `unavailable` and keep the reason in `pdfRenderError`. A failed render MUST NOT block or undo the issue.

#### Scenario: The PDF is rendered

- **GIVEN** a course with `certificateTemplateSlug` set and filinq reachable
- **WHEN** a learner completes the course and the certificate is issued
- **THEN** the certificate has `pdfRenderStatus: rendered` and a `pdfDocumentRef`

#### Scenario: filinq is not installed

- **GIVEN** a course with `certificateTemplateSlug` set and no filinq on the instance
- **WHEN** the certificate is issued
- **THEN** the certificate is issued and signed as before
- **AND** it has `pdfRenderStatus: unavailable` with a reason that names filinq

#### Scenario: Every PDF shows how to check it

- **GIVEN** a rendered certificate PDF
- **WHEN** someone reads it
- **THEN** it prints the certificate's verification URL

### Requirement: Only the holder and the certificate's managers download the PDF

The system MUST offer "PDF downloaden" on the certificate when `pdfRenderStatus` is `rendered`. The download MUST be allowed for the holder, hr and compliance-officers, and MUST be refused for anyone else.

#### Scenario: The holder downloads

- **GIVEN** a rendered certificate held by Tom
- **WHEN** Tom chooses "PDF downloaden"
- **THEN** he receives the PDF

#### Scenario: Another learner tries the download URL

- **GIVEN** a rendered certificate held by Tom
- **WHEN** Sanne calls its download URL
- **THEN** the request is refused

### Requirement: Reissuing renders the PDF again

A reissue of a certificate MUST render its PDF again from the course's current template and replace `pdfDocumentRef`.

#### Scenario: A new template after a rebrand

- **GIVEN** fifty certificates rendered on template `vca-basis`
- **WHEN** the course's template changes to `vca-basis-2027` and an officer reissues all certificates
- **THEN** every reissued certificate has a new `pdfDocumentRef` rendered on `vca-basis-2027`
