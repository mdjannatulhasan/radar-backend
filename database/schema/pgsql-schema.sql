--
-- PostgreSQL database dump
--

\restrict o6DmxOINl3wFAkPbjVtXnKaujMxOQNyOLZr10wSvPM2lBnZ0AYc8dJ1m6RAljfW

-- Dumped from database version 14.21 (Homebrew)
-- Dumped by pg_dump version 14.21 (Homebrew)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: pps_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_alerts (
    id bigint NOT NULL,
    student_id bigint NOT NULL,
    snapshot_period character varying(7) NOT NULL,
    alert_level character varying(20) NOT NULL,
    trigger_reasons json NOT NULL,
    notified_to json,
    resolved_by bigint,
    resolution_action character varying(255),
    resolution_note text,
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_alerts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_alerts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_alerts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_alerts_id_seq OWNED BY public.pps_alerts.id;


--
-- Name: pps_assessments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_assessments (
    id bigint NOT NULL,
    student_id bigint NOT NULL,
    teacher_id bigint,
    subject character varying(100) NOT NULL,
    assessment_type character varying(50) NOT NULL,
    term character varying(20),
    marks_obtained numeric(6,2) NOT NULL,
    total_marks numeric(6,2) NOT NULL,
    percentage numeric(5,2) NOT NULL,
    exam_date date,
    remarks text,
    is_verified boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_assessments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_assessments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_assessments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_assessments_id_seq OWNED BY public.pps_assessments.id;


--
-- Name: pps_attendance; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_attendance (
    id bigint NOT NULL,
    student_id bigint NOT NULL,
    marked_by bigint,
    date date NOT NULL,
    status character varying(20) NOT NULL,
    period smallint,
    subject character varying(100),
    absence_reason character varying(255),
    parent_notified boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_attendance_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_attendance_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_attendance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_attendance_id_seq OWNED BY public.pps_attendance.id;


--
-- Name: pps_behavior_cards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_behavior_cards (
    id bigint NOT NULL,
    student_id bigint NOT NULL,
    issued_by bigint,
    card_type character varying(20) NOT NULL,
    reason text NOT NULL,
    notes text,
    is_integrity_violation boolean DEFAULT false NOT NULL,
    issued_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_behavior_cards_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_behavior_cards_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_behavior_cards_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_behavior_cards_id_seq OWNED BY public.pps_behavior_cards.id;


--
-- Name: pps_class_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_class_sections (
    id bigint NOT NULL,
    class_name character varying(20) NOT NULL,
    section character varying(10) NOT NULL,
    department_id bigint,
    capacity smallint,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    stream_id bigint,
    class_level smallint
);


--
-- Name: pps_class_sections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_class_sections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_class_sections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_class_sections_id_seq OWNED BY public.pps_class_sections.id;


--
-- Name: pps_classroom_ratings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_classroom_ratings (
    id bigint NOT NULL,
    student_id bigint NOT NULL,
    teacher_id bigint,
    subject character varying(100),
    rating_period date NOT NULL,
    period_type character varying(20) DEFAULT 'weekly'::character varying NOT NULL,
    participation smallint,
    attentiveness smallint,
    group_work smallint,
    creativity smallint,
    behavioral_flag character varying(255),
    free_comment text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_classroom_ratings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_classroom_ratings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_classroom_ratings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_classroom_ratings_id_seq OWNED BY public.pps_classroom_ratings.id;


--
-- Name: pps_counseling_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_counseling_sessions (
    id bigint NOT NULL,
    student_id bigint NOT NULL,
    counselor_id bigint,
    referred_by bigint,
    alert_id bigint,
    session_date date NOT NULL,
    session_type character varying(30) DEFAULT 'initial'::character varying NOT NULL,
    session_notes text,
    action_plan text,
    next_session_date date,
    progress_status character varying(30),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    psychometric_scores json,
    special_needs_profile json,
    assessment_tool character varying(120)
);


--
-- Name: pps_counseling_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_counseling_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_counseling_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_counseling_sessions_id_seq OWNED BY public.pps_counseling_sessions.id;


--
-- Name: pps_departments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_departments (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(30),
    description character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_departments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_departments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_departments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_departments_id_seq OWNED BY public.pps_departments.id;


--
-- Name: pps_exam_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_exam_definitions (
    id bigint NOT NULL,
    title character varying(255) NOT NULL,
    code character varying(40),
    assessment_type character varying(50) NOT NULL,
    term character varying(30),
    total_marks numeric(8,2) DEFAULT '100'::numeric NOT NULL,
    exam_date date,
    class_name character varying(20),
    section character varying(10),
    department_id bigint,
    subject_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_exam_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_exam_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_exam_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_exam_definitions_id_seq OWNED BY public.pps_exam_definitions.id;


--
-- Name: pps_extracurricular; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_extracurricular (
    id bigint NOT NULL,
    student_id bigint NOT NULL,
    activity_name character varying(255) NOT NULL,
    category character varying(50),
    role character varying(255),
    achievement character varying(255),
    achievement_level smallint DEFAULT '0'::smallint NOT NULL,
    event_date date NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_extracurricular_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_extracurricular_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_extracurricular_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_extracurricular_id_seq OWNED BY public.pps_extracurricular.id;


--
-- Name: pps_grade_config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_grade_config (
    id bigint NOT NULL,
    school_id bigint,
    min_pct numeric(5,2) NOT NULL,
    max_pct numeric(5,2) NOT NULL,
    letter_grade character varying(5) NOT NULL,
    grade_point numeric(4,2) NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_grade_config_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_grade_config_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_grade_config_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_grade_config_id_seq OWNED BY public.pps_grade_config.id;


--
-- Name: pps_notification_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_notification_logs (
    id bigint NOT NULL,
    type character varying(80) NOT NULL,
    channel character varying(30) DEFAULT 'database'::character varying NOT NULL,
    recipient_role character varying(40) NOT NULL,
    recipient_user_id bigint,
    student_id bigint,
    snapshot_period character varying(7),
    status character varying(30) DEFAULT 'generated'::character varying NOT NULL,
    subject character varying(180) NOT NULL,
    body text NOT NULL,
    meta json,
    generated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_notification_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_notification_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_notification_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_notification_logs_id_seq OWNED BY public.pps_notification_logs.id;


--
-- Name: pps_performance_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_performance_snapshots (
    id bigint NOT NULL,
    student_id bigint NOT NULL,
    snapshot_period character varying(7) NOT NULL,
    academic_score numeric(5,2) NOT NULL,
    attendance_score numeric(5,2) NOT NULL,
    behavior_score numeric(5,2) NOT NULL,
    participation_score numeric(5,2) NOT NULL,
    extracurricular_score numeric(5,2) NOT NULL,
    overall_score numeric(5,2) NOT NULL,
    risk_score numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    alert_level character varying(20) DEFAULT 'none'::character varying NOT NULL,
    trend_direction character varying(20) DEFAULT 'stable'::character varying NOT NULL,
    snapshot_data json,
    calculated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_performance_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_performance_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_performance_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_performance_snapshots_id_seq OWNED BY public.pps_performance_snapshots.id;


--
-- Name: pps_pretest_marks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_pretest_marks (
    id bigint NOT NULL,
    exam_id bigint NOT NULL,
    student_id bigint NOT NULL,
    subject_id bigint NOT NULL,
    ct numeric(5,2),
    attendance numeric(4,2),
    cq numeric(6,2),
    cq_con numeric(6,2),
    mcq numeric(5,2),
    mcq_con numeric(5,2),
    total_obtained numeric(6,2),
    highest_marks numeric(6,2),
    letter_grade character varying(5),
    grade_point numeric(4,2),
    promotion_grade character varying(5),
    entered_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_pretest_marks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_pretest_marks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_pretest_marks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_pretest_marks_id_seq OWNED BY public.pps_pretest_marks.id;


--
-- Name: pps_result_summary; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_result_summary (
    id bigint NOT NULL,
    exam_id bigint NOT NULL,
    student_id bigint NOT NULL,
    total_marks_obtained numeric(8,2),
    total_marks_full numeric(8,2),
    gpa numeric(4,2),
    letter_grade character varying(5),
    discipline character varying(30),
    handwriting character varying(30),
    is_promoted boolean,
    total_presence smallint,
    total_working_days smallint,
    class_position smallint,
    total_students_in_class smallint,
    computed_at timestamp(0) without time zone,
    computed_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_result_summary_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_result_summary_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_result_summary_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_result_summary_id_seq OWNED BY public.pps_result_summary.id;


--
-- Name: pps_school_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_school_configs (
    id bigint NOT NULL,
    weight_academic numeric(4,2) DEFAULT 0.4 NOT NULL,
    weight_attendance numeric(4,2) DEFAULT 0.2 NOT NULL,
    weight_behavior numeric(4,2) DEFAULT 0.15 NOT NULL,
    weight_participation numeric(4,2) DEFAULT 0.15 NOT NULL,
    weight_extracurricular numeric(4,2) DEFAULT 0.1 NOT NULL,
    threshold_risk_watch numeric(5,2) DEFAULT '20'::numeric NOT NULL,
    threshold_risk_warning numeric(5,2) DEFAULT '40'::numeric NOT NULL,
    threshold_risk_urgent numeric(5,2) DEFAULT '70'::numeric NOT NULL,
    threshold_attendance_watch numeric(5,2) DEFAULT '85'::numeric NOT NULL,
    threshold_attendance_warning numeric(5,2) DEFAULT '75'::numeric NOT NULL,
    threshold_attendance_urgent numeric(5,2) DEFAULT '60'::numeric NOT NULL,
    threshold_grade_drop_warning numeric(5,2) DEFAULT '10'::numeric NOT NULL,
    threshold_grade_drop_urgent numeric(5,2) DEFAULT '20'::numeric NOT NULL,
    threshold_yellow_cards_warning smallint DEFAULT '3'::smallint NOT NULL,
    notify_parent_on_warning boolean DEFAULT true NOT NULL,
    notify_parent_on_watch boolean DEFAULT false NOT NULL,
    send_monthly_parent_report boolean DEFAULT true NOT NULL,
    send_weekly_principal_summary boolean DEFAULT true NOT NULL,
    notify_guardian_email_on_urgent boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_school_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_school_configs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_school_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_school_configs_id_seq OWNED BY public.pps_school_configs.id;


--
-- Name: pps_streams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_streams (
    id bigint NOT NULL,
    name character varying(60) NOT NULL,
    code character varying(20),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_streams_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_streams_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_streams_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_streams_id_seq OWNED BY public.pps_streams.id;


--
-- Name: pps_subjects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_subjects (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(30),
    department_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_subjects_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_subjects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_subjects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_subjects_id_seq OWNED BY public.pps_subjects.id;


--
-- Name: pps_teacher_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_teacher_assignments (
    id bigint NOT NULL,
    teacher_id bigint NOT NULL,
    class_name character varying(20) NOT NULL,
    section character varying(10) NOT NULL,
    subject character varying(100),
    is_class_teacher boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_teacher_assignments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_teacher_assignments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_teacher_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_teacher_assignments_id_seq OWNED BY public.pps_teacher_assignments.id;


--
-- Name: pps_term_marks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pps_term_marks (
    id bigint NOT NULL,
    exam_id bigint NOT NULL,
    student_id bigint NOT NULL,
    subject_id bigint NOT NULL,
    spot_test numeric(5,2),
    spot_test_con numeric(5,2),
    class_test2 numeric(5,2),
    class_test2_con numeric(5,2),
    attendance numeric(4,2),
    term_marks numeric(6,2),
    term_con numeric(6,2),
    vt numeric(5,2),
    vt_con numeric(5,2),
    total_obtained numeric(6,2),
    highest_marks numeric(6,2),
    letter_grade character varying(5),
    grade_point numeric(4,2),
    entered_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pps_term_marks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pps_term_marks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pps_term_marks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pps_term_marks_id_seq OWNED BY public.pps_term_marks.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: students; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.students (
    id bigint NOT NULL,
    student_code character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    class_name character varying(20) NOT NULL,
    section character varying(10) NOT NULL,
    roll_number smallint,
    photo_path character varying(255),
    guardian_name character varying(255),
    guardian_phone character varying(255),
    guardian_email character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    admission_date date,
    current_gpa numeric(4,2),
    current_grade character varying(10),
    class_rank smallint,
    private_tuition_subjects json,
    private_tuition_notes text,
    family_status character varying(120),
    economic_status character varying(120),
    scholarship_status character varying(120),
    health_notes text,
    allergies character varying(255),
    medications character varying(255),
    residence_change_note character varying(255),
    special_needs json,
    confidential_context text,
    guardian_profession character varying(255),
    guardian_profession_category character varying(60),
    guardian_time_availability character varying(20),
    willingness_score smallint,
    ability_score smallint,
    student_quadrant character varying(30),
    economically_vulnerable boolean DEFAULT false NOT NULL,
    stream_id bigint
);


--
-- Name: students_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.students_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: students_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.students_id_seq OWNED BY public.students.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    role character varying(30) DEFAULT 'teacher'::character varying NOT NULL
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: pps_alerts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_alerts ALTER COLUMN id SET DEFAULT nextval('public.pps_alerts_id_seq'::regclass);


--
-- Name: pps_assessments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_assessments ALTER COLUMN id SET DEFAULT nextval('public.pps_assessments_id_seq'::regclass);


--
-- Name: pps_attendance id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_attendance ALTER COLUMN id SET DEFAULT nextval('public.pps_attendance_id_seq'::regclass);


--
-- Name: pps_behavior_cards id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_behavior_cards ALTER COLUMN id SET DEFAULT nextval('public.pps_behavior_cards_id_seq'::regclass);


--
-- Name: pps_class_sections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_class_sections ALTER COLUMN id SET DEFAULT nextval('public.pps_class_sections_id_seq'::regclass);


--
-- Name: pps_classroom_ratings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_classroom_ratings ALTER COLUMN id SET DEFAULT nextval('public.pps_classroom_ratings_id_seq'::regclass);


--
-- Name: pps_counseling_sessions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_counseling_sessions ALTER COLUMN id SET DEFAULT nextval('public.pps_counseling_sessions_id_seq'::regclass);


--
-- Name: pps_departments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_departments ALTER COLUMN id SET DEFAULT nextval('public.pps_departments_id_seq'::regclass);


--
-- Name: pps_exam_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_exam_definitions ALTER COLUMN id SET DEFAULT nextval('public.pps_exam_definitions_id_seq'::regclass);


--
-- Name: pps_extracurricular id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_extracurricular ALTER COLUMN id SET DEFAULT nextval('public.pps_extracurricular_id_seq'::regclass);


--
-- Name: pps_grade_config id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_grade_config ALTER COLUMN id SET DEFAULT nextval('public.pps_grade_config_id_seq'::regclass);


--
-- Name: pps_notification_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_notification_logs ALTER COLUMN id SET DEFAULT nextval('public.pps_notification_logs_id_seq'::regclass);


--
-- Name: pps_performance_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_performance_snapshots ALTER COLUMN id SET DEFAULT nextval('public.pps_performance_snapshots_id_seq'::regclass);


--
-- Name: pps_pretest_marks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_pretest_marks ALTER COLUMN id SET DEFAULT nextval('public.pps_pretest_marks_id_seq'::regclass);


--
-- Name: pps_result_summary id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_result_summary ALTER COLUMN id SET DEFAULT nextval('public.pps_result_summary_id_seq'::regclass);


--
-- Name: pps_school_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_school_configs ALTER COLUMN id SET DEFAULT nextval('public.pps_school_configs_id_seq'::regclass);


--
-- Name: pps_streams id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_streams ALTER COLUMN id SET DEFAULT nextval('public.pps_streams_id_seq'::regclass);


--
-- Name: pps_subjects id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_subjects ALTER COLUMN id SET DEFAULT nextval('public.pps_subjects_id_seq'::regclass);


--
-- Name: pps_teacher_assignments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_teacher_assignments ALTER COLUMN id SET DEFAULT nextval('public.pps_teacher_assignments_id_seq'::regclass);


--
-- Name: pps_term_marks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_term_marks ALTER COLUMN id SET DEFAULT nextval('public.pps_term_marks_id_seq'::regclass);


--
-- Name: students id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students ALTER COLUMN id SET DEFAULT nextval('public.students_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: pps_alerts pps_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_alerts
    ADD CONSTRAINT pps_alerts_pkey PRIMARY KEY (id);


--
-- Name: pps_assessments pps_assessments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_assessments
    ADD CONSTRAINT pps_assessments_pkey PRIMARY KEY (id);


--
-- Name: pps_attendance pps_attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_attendance
    ADD CONSTRAINT pps_attendance_pkey PRIMARY KEY (id);


--
-- Name: pps_attendance pps_attendance_unique_daily; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_attendance
    ADD CONSTRAINT pps_attendance_unique_daily UNIQUE (student_id, date, period);


--
-- Name: pps_behavior_cards pps_behavior_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_behavior_cards
    ADD CONSTRAINT pps_behavior_cards_pkey PRIMARY KEY (id);


--
-- Name: pps_class_sections pps_class_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_class_sections
    ADD CONSTRAINT pps_class_sections_pkey PRIMARY KEY (id);


--
-- Name: pps_class_sections pps_class_sections_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_class_sections
    ADD CONSTRAINT pps_class_sections_unique UNIQUE (class_name, section);


--
-- Name: pps_classroom_ratings pps_classroom_ratings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_classroom_ratings
    ADD CONSTRAINT pps_classroom_ratings_pkey PRIMARY KEY (id);


--
-- Name: pps_counseling_sessions pps_counseling_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_counseling_sessions
    ADD CONSTRAINT pps_counseling_sessions_pkey PRIMARY KEY (id);


--
-- Name: pps_departments pps_departments_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_departments
    ADD CONSTRAINT pps_departments_code_unique UNIQUE (code);


--
-- Name: pps_departments pps_departments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_departments
    ADD CONSTRAINT pps_departments_pkey PRIMARY KEY (id);


--
-- Name: pps_exam_definitions pps_exam_definitions_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_exam_definitions
    ADD CONSTRAINT pps_exam_definitions_code_unique UNIQUE (code);


--
-- Name: pps_exam_definitions pps_exam_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_exam_definitions
    ADD CONSTRAINT pps_exam_definitions_pkey PRIMARY KEY (id);


--
-- Name: pps_extracurricular pps_extracurricular_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_extracurricular
    ADD CONSTRAINT pps_extracurricular_pkey PRIMARY KEY (id);


--
-- Name: pps_grade_config pps_grade_config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_grade_config
    ADD CONSTRAINT pps_grade_config_pkey PRIMARY KEY (id);


--
-- Name: pps_grade_config pps_grade_config_school_id_letter_grade_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_grade_config
    ADD CONSTRAINT pps_grade_config_school_id_letter_grade_unique UNIQUE (school_id, letter_grade);


--
-- Name: pps_notification_logs pps_notification_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_notification_logs
    ADD CONSTRAINT pps_notification_logs_pkey PRIMARY KEY (id);


--
-- Name: pps_performance_snapshots pps_performance_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_performance_snapshots
    ADD CONSTRAINT pps_performance_snapshots_pkey PRIMARY KEY (id);


--
-- Name: pps_performance_snapshots pps_performance_snapshots_student_id_snapshot_period_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_performance_snapshots
    ADD CONSTRAINT pps_performance_snapshots_student_id_snapshot_period_unique UNIQUE (student_id, snapshot_period);


--
-- Name: pps_pretest_marks pps_pretest_marks_exam_id_student_id_subject_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_pretest_marks
    ADD CONSTRAINT pps_pretest_marks_exam_id_student_id_subject_id_unique UNIQUE (exam_id, student_id, subject_id);


--
-- Name: pps_pretest_marks pps_pretest_marks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_pretest_marks
    ADD CONSTRAINT pps_pretest_marks_pkey PRIMARY KEY (id);


--
-- Name: pps_result_summary pps_result_summary_exam_id_student_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_result_summary
    ADD CONSTRAINT pps_result_summary_exam_id_student_id_unique UNIQUE (exam_id, student_id);


--
-- Name: pps_result_summary pps_result_summary_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_result_summary
    ADD CONSTRAINT pps_result_summary_pkey PRIMARY KEY (id);


--
-- Name: pps_school_configs pps_school_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_school_configs
    ADD CONSTRAINT pps_school_configs_pkey PRIMARY KEY (id);


--
-- Name: pps_streams pps_streams_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_streams
    ADD CONSTRAINT pps_streams_code_unique UNIQUE (code);


--
-- Name: pps_streams pps_streams_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_streams
    ADD CONSTRAINT pps_streams_name_unique UNIQUE (name);


--
-- Name: pps_streams pps_streams_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_streams
    ADD CONSTRAINT pps_streams_pkey PRIMARY KEY (id);


--
-- Name: pps_subjects pps_subjects_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_subjects
    ADD CONSTRAINT pps_subjects_code_unique UNIQUE (code);


--
-- Name: pps_subjects pps_subjects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_subjects
    ADD CONSTRAINT pps_subjects_pkey PRIMARY KEY (id);


--
-- Name: pps_teacher_assignments pps_teacher_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_teacher_assignments
    ADD CONSTRAINT pps_teacher_assignments_pkey PRIMARY KEY (id);


--
-- Name: pps_teacher_assignments pps_teacher_assignments_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_teacher_assignments
    ADD CONSTRAINT pps_teacher_assignments_unique UNIQUE (teacher_id, class_name, section, subject);


--
-- Name: pps_term_marks pps_term_marks_exam_id_student_id_subject_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_term_marks
    ADD CONSTRAINT pps_term_marks_exam_id_student_id_subject_id_unique UNIQUE (exam_id, student_id, subject_id);


--
-- Name: pps_term_marks pps_term_marks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_term_marks
    ADD CONSTRAINT pps_term_marks_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: students students_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_pkey PRIMARY KEY (id);


--
-- Name: students students_student_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_student_code_unique UNIQUE (student_code);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: pps_alerts_alert_level_resolved_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_alerts_alert_level_resolved_at_index ON public.pps_alerts USING btree (alert_level, resolved_at);


--
-- Name: pps_alerts_student_id_snapshot_period_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_alerts_student_id_snapshot_period_index ON public.pps_alerts USING btree (student_id, snapshot_period);


--
-- Name: pps_assessments_student_id_exam_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_assessments_student_id_exam_date_index ON public.pps_assessments USING btree (student_id, exam_date);


--
-- Name: pps_assessments_student_id_subject_term_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_assessments_student_id_subject_term_index ON public.pps_assessments USING btree (student_id, subject, term);


--
-- Name: pps_attendance_date_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_attendance_date_status_index ON public.pps_attendance USING btree (date, status);


--
-- Name: pps_attendance_student_id_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_attendance_student_id_date_index ON public.pps_attendance USING btree (student_id, date);


--
-- Name: pps_behavior_cards_student_id_issued_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_behavior_cards_student_id_issued_at_index ON public.pps_behavior_cards USING btree (student_id, issued_at);


--
-- Name: pps_classroom_ratings_student_id_rating_period_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_classroom_ratings_student_id_rating_period_index ON public.pps_classroom_ratings USING btree (student_id, rating_period);


--
-- Name: pps_counseling_sessions_counselor_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_counseling_sessions_counselor_id_index ON public.pps_counseling_sessions USING btree (counselor_id);


--
-- Name: pps_counseling_sessions_student_id_session_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_counseling_sessions_student_id_session_date_index ON public.pps_counseling_sessions USING btree (student_id, session_date);


--
-- Name: pps_extracurricular_student_id_event_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_extracurricular_student_id_event_date_index ON public.pps_extracurricular USING btree (student_id, event_date);


--
-- Name: pps_grade_config_school_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_grade_config_school_id_index ON public.pps_grade_config USING btree (school_id);


--
-- Name: pps_notification_logs_recipient_role_recipient_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_notification_logs_recipient_role_recipient_user_id_index ON public.pps_notification_logs USING btree (recipient_role, recipient_user_id);


--
-- Name: pps_notification_logs_student_id_snapshot_period_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_notification_logs_student_id_snapshot_period_index ON public.pps_notification_logs USING btree (student_id, snapshot_period);


--
-- Name: pps_notification_logs_type_snapshot_period_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_notification_logs_type_snapshot_period_index ON public.pps_notification_logs USING btree (type, snapshot_period);


--
-- Name: pps_performance_snapshots_snapshot_period_alert_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_performance_snapshots_snapshot_period_alert_level_index ON public.pps_performance_snapshots USING btree (snapshot_period, alert_level);


--
-- Name: pps_performance_snapshots_snapshot_period_risk_score_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_performance_snapshots_snapshot_period_risk_score_index ON public.pps_performance_snapshots USING btree (snapshot_period, risk_score);


--
-- Name: pps_pretest_marks_exam_id_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_pretest_marks_exam_id_subject_id_index ON public.pps_pretest_marks USING btree (exam_id, subject_id);


--
-- Name: pps_result_summary_exam_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_result_summary_exam_id_index ON public.pps_result_summary USING btree (exam_id);


--
-- Name: pps_teacher_assignments_class_name_section_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_teacher_assignments_class_name_section_index ON public.pps_teacher_assignments USING btree (class_name, section);


--
-- Name: pps_term_marks_exam_id_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pps_term_marks_exam_id_subject_id_index ON public.pps_term_marks USING btree (exam_id, subject_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: students_class_name_section_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX students_class_name_section_index ON public.students USING btree (class_name, section);


--
-- Name: pps_alerts pps_alerts_resolved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_alerts
    ADD CONSTRAINT pps_alerts_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_alerts pps_alerts_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_alerts
    ADD CONSTRAINT pps_alerts_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_assessments pps_assessments_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_assessments
    ADD CONSTRAINT pps_assessments_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_assessments pps_assessments_teacher_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_assessments
    ADD CONSTRAINT pps_assessments_teacher_id_foreign FOREIGN KEY (teacher_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_attendance pps_attendance_marked_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_attendance
    ADD CONSTRAINT pps_attendance_marked_by_foreign FOREIGN KEY (marked_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_attendance pps_attendance_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_attendance
    ADD CONSTRAINT pps_attendance_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_behavior_cards pps_behavior_cards_issued_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_behavior_cards
    ADD CONSTRAINT pps_behavior_cards_issued_by_foreign FOREIGN KEY (issued_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_behavior_cards pps_behavior_cards_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_behavior_cards
    ADD CONSTRAINT pps_behavior_cards_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_class_sections pps_class_sections_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_class_sections
    ADD CONSTRAINT pps_class_sections_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.pps_departments(id) ON DELETE SET NULL;


--
-- Name: pps_class_sections pps_class_sections_stream_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_class_sections
    ADD CONSTRAINT pps_class_sections_stream_id_foreign FOREIGN KEY (stream_id) REFERENCES public.pps_streams(id) ON DELETE SET NULL;


--
-- Name: pps_classroom_ratings pps_classroom_ratings_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_classroom_ratings
    ADD CONSTRAINT pps_classroom_ratings_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_classroom_ratings pps_classroom_ratings_teacher_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_classroom_ratings
    ADD CONSTRAINT pps_classroom_ratings_teacher_id_foreign FOREIGN KEY (teacher_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_counseling_sessions pps_counseling_sessions_alert_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_counseling_sessions
    ADD CONSTRAINT pps_counseling_sessions_alert_id_foreign FOREIGN KEY (alert_id) REFERENCES public.pps_alerts(id) ON DELETE SET NULL;


--
-- Name: pps_counseling_sessions pps_counseling_sessions_counselor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_counseling_sessions
    ADD CONSTRAINT pps_counseling_sessions_counselor_id_foreign FOREIGN KEY (counselor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_counseling_sessions pps_counseling_sessions_referred_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_counseling_sessions
    ADD CONSTRAINT pps_counseling_sessions_referred_by_foreign FOREIGN KEY (referred_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_counseling_sessions pps_counseling_sessions_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_counseling_sessions
    ADD CONSTRAINT pps_counseling_sessions_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_exam_definitions pps_exam_definitions_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_exam_definitions
    ADD CONSTRAINT pps_exam_definitions_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.pps_departments(id) ON DELETE SET NULL;


--
-- Name: pps_exam_definitions pps_exam_definitions_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_exam_definitions
    ADD CONSTRAINT pps_exam_definitions_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.pps_subjects(id) ON DELETE SET NULL;


--
-- Name: pps_extracurricular pps_extracurricular_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_extracurricular
    ADD CONSTRAINT pps_extracurricular_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_notification_logs pps_notification_logs_recipient_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_notification_logs
    ADD CONSTRAINT pps_notification_logs_recipient_user_id_foreign FOREIGN KEY (recipient_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_notification_logs pps_notification_logs_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_notification_logs
    ADD CONSTRAINT pps_notification_logs_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE SET NULL;


--
-- Name: pps_performance_snapshots pps_performance_snapshots_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_performance_snapshots
    ADD CONSTRAINT pps_performance_snapshots_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_pretest_marks pps_pretest_marks_entered_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_pretest_marks
    ADD CONSTRAINT pps_pretest_marks_entered_by_foreign FOREIGN KEY (entered_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_pretest_marks pps_pretest_marks_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_pretest_marks
    ADD CONSTRAINT pps_pretest_marks_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.pps_exam_definitions(id) ON DELETE CASCADE;


--
-- Name: pps_pretest_marks pps_pretest_marks_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_pretest_marks
    ADD CONSTRAINT pps_pretest_marks_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_pretest_marks pps_pretest_marks_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_pretest_marks
    ADD CONSTRAINT pps_pretest_marks_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.pps_subjects(id) ON DELETE CASCADE;


--
-- Name: pps_result_summary pps_result_summary_computed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_result_summary
    ADD CONSTRAINT pps_result_summary_computed_by_foreign FOREIGN KEY (computed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_result_summary pps_result_summary_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_result_summary
    ADD CONSTRAINT pps_result_summary_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.pps_exam_definitions(id) ON DELETE CASCADE;


--
-- Name: pps_result_summary pps_result_summary_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_result_summary
    ADD CONSTRAINT pps_result_summary_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_subjects pps_subjects_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_subjects
    ADD CONSTRAINT pps_subjects_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.pps_departments(id) ON DELETE SET NULL;


--
-- Name: pps_teacher_assignments pps_teacher_assignments_teacher_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_teacher_assignments
    ADD CONSTRAINT pps_teacher_assignments_teacher_id_foreign FOREIGN KEY (teacher_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: pps_term_marks pps_term_marks_entered_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_term_marks
    ADD CONSTRAINT pps_term_marks_entered_by_foreign FOREIGN KEY (entered_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pps_term_marks pps_term_marks_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_term_marks
    ADD CONSTRAINT pps_term_marks_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.pps_exam_definitions(id) ON DELETE CASCADE;


--
-- Name: pps_term_marks pps_term_marks_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_term_marks
    ADD CONSTRAINT pps_term_marks_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: pps_term_marks pps_term_marks_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pps_term_marks
    ADD CONSTRAINT pps_term_marks_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.pps_subjects(id) ON DELETE CASCADE;


--
-- Name: students students_stream_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_stream_id_foreign FOREIGN KEY (stream_id) REFERENCES public.pps_streams(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict o6DmxOINl3wFAkPbjVtXnKaujMxOQNyOLZr10wSvPM2lBnZ0AYc8dJ1m6RAljfW

--
-- PostgreSQL database dump
--

\restrict YMch5UHvqg05dUx71BzjZefu7cPrIeTrwnyciRFRug9xUI1qKP7y5nZwlC1rL0S

-- Dumped from database version 14.21 (Homebrew)
-- Dumped by pg_dump version 14.21 (Homebrew)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_04_02_000100_create_students_table	1
5	2026_04_02_000110_create_pps_school_configs_table	1
6	2026_04_02_000120_create_pps_assessments_table	1
7	2026_04_02_000130_create_pps_attendance_table	1
8	2026_04_02_000140_create_pps_behavior_cards_table	1
9	2026_04_02_000150_create_pps_classroom_ratings_table	1
10	2026_04_02_000160_create_pps_extracurricular_table	1
11	2026_04_02_000170_create_pps_performance_snapshots_table	1
12	2026_04_02_000180_create_pps_alerts_table	1
13	2026_04_02_000190_add_role_to_users_table	1
14	2026_04_02_000200_create_pps_counseling_sessions_table	1
15	2026_04_02_000210_add_context_fields_to_students_table	1
16	2026_04_02_000220_add_psychometric_fields_to_counseling_sessions_table	1
17	2026_04_02_000230_create_pps_notification_logs_table	1
18	2026_04_02_000240_create_personal_access_tokens_table	1
19	2026_04_03_000300_create_pps_teacher_assignments_table	1
20	2026_04_05_000400_create_pps_departments_table	1
21	2026_04_05_000410_create_pps_class_sections_table	1
22	2026_04_05_000420_create_pps_subjects_table	1
23	2026_04_05_000430_create_pps_exam_definitions_table	1
24	2026_04_14_000500_add_evaluation_fields_to_students_table	2
25	2026_04_17_000600_create_pps_streams_table	2
26	2026_04_17_000610_add_stream_id_to_students_and_class_sections	2
27	2026_04_17_000620_create_pps_grade_config_table	2
28	2026_04_17_000630_create_pps_term_marks_table	2
29	2026_04_17_000640_create_pps_pretest_marks_table	2
30	2026_04_17_000650_create_pps_result_summary_table	2
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 30, true);


--
-- PostgreSQL database dump complete
--

\unrestrict YMch5UHvqg05dUx71BzjZefu7cPrIeTrwnyciRFRug9xUI1qKP7y5nZwlC1rL0S

