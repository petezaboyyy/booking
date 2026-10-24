#!/bin/sh
# Run every booking test suite. Needs the docker compose stack up.
#   sh tests/run_all.sh
set -e
cd "$(dirname "$0")/.."

for t in api_dispatch_test guest_access_test config_env_preserve_test translation_coverage_test approvals_table_test course_code_category_test category_english_name_test topbar_button_test admin_mobile_topbar_test profile_super_admin_test booking_guardrails_test booking_validation_test booking_security_test booking_csv_export_test booking_occurrence_integration_test calendar_category_id_test booking_topic_label_test booking_use_label_cache_test room_unique_key_test booking_competition_test booking_time_step_test booking_decider_names_test auth_session_test css_ascii_test; do
    printf '\n======== %s ========\n' "$t"
    docker compose exec -T web php "tests/$t.php"
done

printf '\n======== all suites passed ========\n'
