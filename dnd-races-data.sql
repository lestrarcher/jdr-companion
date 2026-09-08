--
-- PostgreSQL database dump
--

\restrict YWjFypVGLWwgLa6yecJI9HY4FMcMiELr7zaT8OaHIL45vOipG514aF8rQPyrAve

-- Dumped from database version 17.10
-- Dumped by pg_dump version 17.10

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: character_race; Type: TABLE DATA; Schema: public; Owner: jdr
--

INSERT INTO public.character_race VALUES (1, 'human', 'Humain', NULL, false, '2026-09-06 20:41:27', '2026-09-07 15:16:01', NULL, 0);
INSERT INTO public.character_race VALUES (3, 'variant-human', 'Humain variant', NULL, false, '2026-09-06 20:41:27', '2026-09-07 15:16:01', 1, 1);
INSERT INTO public.character_race VALUES (2, 'standard-human', 'Humain standard', NULL, false, '2026-09-06 20:41:27', '2026-09-07 15:16:01', 1, 0);
INSERT INTO public.character_race VALUES (4, 'elf', 'Elfe', NULL, false, '2026-09-06 20:41:27', '2026-09-07 15:16:01', NULL, 0);
INSERT INTO public.character_race VALUES (5, 'high-elf', 'Haut-elfe', NULL, false, '2026-09-06 20:41:27', '2026-09-07 15:16:01', 4, 0);
INSERT INTO public.character_race VALUES (6, 'gnome', 'Gnome', NULL, false, '2026-09-06 20:41:27', '2026-09-07 15:16:01', NULL, 0);
INSERT INTO public.character_race VALUES (7, 'forest-gnome', 'Gnome des forêts', NULL, false, '2026-09-06 20:41:27', '2026-09-07 15:16:01', 6, 0);
INSERT INTO public.character_race VALUES (8, 'kobold', 'Kobold', NULL, false, '2026-09-06 20:41:27', '2026-09-07 15:16:01', NULL, 0);
INSERT INTO public.character_race VALUES (9, 'wood-elf', 'Elfe des bois', NULL, false, '2026-09-08 13:22:06', '2026-09-08 13:22:06', 4, 0);
INSERT INTO public.character_race VALUES (10, 'eladrin', 'Éladrin', NULL, false, '2026-09-08 13:30:23', '2026-09-08 13:30:23', NULL, 1);
INSERT INTO public.character_race VALUES (12, 'genasi', 'Génasi', NULL, false, '2026-09-08 13:33:56', '2026-09-08 13:33:56', NULL, 0);
INSERT INTO public.character_race VALUES (13, 'water-genasi', 'Génasi de l''eau', NULL, false, '2026-09-08 13:36:19', '2026-09-08 13:36:19', 12, 0);
INSERT INTO public.character_race VALUES (11, 'goliath', 'Goliath', NULL, false, '2026-09-08 13:31:22', '2026-09-08 13:37:36', NULL, 0);


--
-- Data for Name: race_ability_modifier; Type: TABLE DATA; Schema: public; Owner: jdr
--

INSERT INTO public.race_ability_modifier VALUES (1, 'strength', 1, NULL, 2);
INSERT INTO public.race_ability_modifier VALUES (2, 'dexterity', 1, NULL, 2);
INSERT INTO public.race_ability_modifier VALUES (3, 'constitution', 1, NULL, 2);
INSERT INTO public.race_ability_modifier VALUES (4, 'intelligence', 1, NULL, 2);
INSERT INTO public.race_ability_modifier VALUES (5, 'wisdom', 1, NULL, 2);
INSERT INTO public.race_ability_modifier VALUES (6, 'charisma', 1, NULL, 2);
INSERT INTO public.race_ability_modifier VALUES (7, NULL, 1, 'ability-choice-1', 3);
INSERT INTO public.race_ability_modifier VALUES (8, NULL, 1, 'ability-choice-2', 3);
INSERT INTO public.race_ability_modifier VALUES (9, 'dexterity', 2, NULL, 4);
INSERT INTO public.race_ability_modifier VALUES (10, 'intelligence', 1, NULL, 5);
INSERT INTO public.race_ability_modifier VALUES (11, 'intelligence', 2, NULL, 6);
INSERT INTO public.race_ability_modifier VALUES (12, 'dexterity', 1, NULL, 7);
INSERT INTO public.race_ability_modifier VALUES (13, 'wisdom', 1, NULL, 9);
INSERT INTO public.race_ability_modifier VALUES (14, NULL, 2, 'primary', 10);
INSERT INTO public.race_ability_modifier VALUES (15, NULL, 1, 'secondary', 10);
INSERT INTO public.race_ability_modifier VALUES (16, NULL, 2, 'primary', 11);
INSERT INTO public.race_ability_modifier VALUES (17, NULL, 1, 'secondary', 11);
INSERT INTO public.race_ability_modifier VALUES (18, NULL, 2, 'primary', 12);
INSERT INTO public.race_ability_modifier VALUES (19, NULL, 1, 'secondary', 12);


--
-- Name: character_race_id_seq; Type: SEQUENCE SET; Schema: public; Owner: jdr
--

SELECT pg_catalog.setval('public.character_race_id_seq', 13, true);


--
-- Name: race_ability_modifier_id_seq; Type: SEQUENCE SET; Schema: public; Owner: jdr
--

SELECT pg_catalog.setval('public.race_ability_modifier_id_seq', 19, true);


--
-- PostgreSQL database dump complete
--

\unrestrict YWjFypVGLWwgLa6yecJI9HY4FMcMiELr7zaT8OaHIL45vOipG514aF8rQPyrAve

