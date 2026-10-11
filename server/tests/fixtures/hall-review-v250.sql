-- isolated fixture mirrored from workers/server/database/demo/hall-review-v250.sql
SELECT 'BXDEMOHALL01','consumer','construction','multi_quote';
SELECT 'BXDEMOHALL02','consumer','construction','single_grab';
SELECT 'BXDEMOHALL03','consumer','measurement','multi_quote';
SELECT 'BXDEMOHALL04','merchant','construction','multi_quote';
SELECT 'BXDEMOHALL05','platform','construction','multi_quote';
SELECT 'BXDEMOHALL06','platform','measurement','multi_quote';
-- production source must keep all six orders is_demo=1 and supplement order_platform_terms / order_measurement_states.
