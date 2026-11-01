"""Shared export contract for the All Certificate page."""

ALL_CERTIFICATE_COLUMNS = {
    'date_received': 'Date Received',
    'client_name': 'Clients',
    'name': 'Names',
    'institution': 'Institution',
    'course': 'Courses',
    'qualification': 'Qualification',
    'grade': 'Grade',
    'session': 'Session',
    'matric_number': 'Exam Number/Call-up Number/Matric number',
}

# These names intentionally follow the supplied workbook, including its singular
# "EXAMINATION" naming. They are export labels, not authenticity decisions.
TEMPLATE_INSTITUTIONS = {
    'NYSC': 'NATIONAL YOUTH SERVICE CORPS',
    'WAEC': 'WEST AFRICA EXAMINATION COUNCIL',
    'NECO': 'NATIONAL EXAMINATION COUNCIL',
    'NABTEB': 'NATIONAL BUSINESS AND TECHNICAL EXAMINATIONS BOARD',
}


def normalize_all_certificate(record):
    """Apply only explicit template conventions; leave academic grades intact."""
    result = dict(record)
    kind = str(result.pop('certificate_type', '') or '').strip().upper()
    if kind not in TEMPLATE_INSTITUTIONS:
        institution = str(result.get('institution', '') or '').strip().upper()
        aliases = {
            'NATIONAL YOUTH SERVICE CORPS': 'NYSC',
            'WEST AFRICAN EXAMINATIONS COUNCIL': 'WAEC',
            'WEST AFRICA EXAMINATION COUNCIL': 'WAEC',
            'THE WEST AFRICAN EXAMINATIONS COUNCIL': 'WAEC',
            'NATIONAL EXAMINATIONS COUNCIL': 'NECO',
            'NATIONAL EXAMINATION COUNCIL': 'NECO',
            'NATIONAL BUSINESS AND TECHNICAL EXAMINATIONS BOARD': 'NABTEB',
        }
        kind = aliases.get(institution, institution if institution in TEMPLATE_INSTITUTIONS else kind)
    if kind in TEMPLATE_INSTITUTIONS:
        result.update(institution=TEMPLATE_INSTITUTIONS[kind], course=kind,
                      qualification=kind, grade='PASS')
    return result


def all_certificate_export(frame):
    """Nine columns, in template order. Internal CSV keys stay compatible with RCS."""
    return frame.reindex(columns=list(ALL_CERTIFICATE_COLUMNS), fill_value='').rename(
        columns=ALL_CERTIFICATE_COLUMNS
    )
