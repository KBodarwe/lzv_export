#!/bin/sh
#
# Copy the exported SIPs to a predefined location for Rosetta ingest
# DO NOT CHANGE the row numbers of the Variables OJS_DIR and OUT_DIR, as these will be filled automatically.

OJS_DIR="/var/www/ojs/"
OUT_DIR="/data/lzv/"
LOG_DIR="/data/lzv/logs/"

day=`date +'%a'`
dump_file=${DUMP_DIR}${DUMP}_${day}.sql
log_file=${LOG_DIR}mysqldump_${DUMP}_${day}.log


echo "---TIMESTAMP---" > $log_file
echo `date` >> $log_file
echo "This server is: `/bin/hostname`" >> $log_file
echo "This script is: $0" >> $log_file
echo "This file is: ${log_file}" >> $log_file
echo "---DUMPING---" >> $log_file
echo "Dump file: ${dump_file}" >> $log_file
/usr/bin/mysqldump -u ${MYSQL_USER} --skip-extended-insert -p${MYSQL_PW} ${MYSQL_DB} > ${dump_file} 2>>${log_file} \
        && /bin/gzip -f ${dump_file} 2>> $log_file

status=$?

if [ $status -eq 0 ]
then
        echo "All seemed to have gone well. Good!" >> $log_file
else
        echo "ERROR: There was a problem with the dump!" >> $log_file
        /usr/bin/mail -s "Error during mysqldump of OJS" ${EMAILADDRESS} <${log_file}
fi

echo "---TIMESTAMP---" >> $log_file
echo `date` >> $log_file

echo "---DONE---" >> $log_file
exit $status
