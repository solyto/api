deploy: check
	cd deployment && \
		ansible-playbook -i hosts.ini deploy.yml

check:
	composer test
	composer analyse

audit:
	composer audit

